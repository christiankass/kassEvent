<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Models\Invitation;
use App\Modules\Access\Notifications\InvitationNotification;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->organization = $this->createOrganization($this->owner = User::factory()->create(['name' => 'Patrick']), 'Kin Events');
    }

    /** Invite une adresse en tant que propriétaire et renvoie le jeton envoyé par e-mail. */
    private function invite(string $email = 'grace@example.com', string $role = 'event_manager'): string
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/invitations', ['email' => $email, 'role' => $role], $this->headers())->assertCreated();

        return $this->sentToken($email);
    }

    private function sentToken(string $email): string
    {
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function ($notification, $channels, $notifiable) use ($email, &$token) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }
            parse_str(parse_url($notification->toMail($notifiable)->actionUrl, PHP_URL_QUERY), $query);
            $token = $query['jeton'];

            return $query['organisation'] === $this->organization->id;
        });

        return $token;
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    private function link(string $token): array
    {
        return ['organization' => $this->organization->id, 'token' => $token];
    }

    public function test_an_owner_invites_by_email_with_a_role(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/invitations', ['email' => ' Grace@Example.com ', 'role' => 'event_manager'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.email', 'grace@example.com')
            ->assertJsonPath('data.role', 'event_manager')
            ->assertJsonMissingPath('data.token_hash');

        $this->assertNotEmpty($this->sentToken('grace@example.com'));
        $this->getJson('/api/v1/invitations', $this->headers())->assertJsonCount(1, 'data');
    }

    public function test_the_owner_role_cannot_be_given_and_members_cannot_be_reinvited(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/invitations', ['email' => 'x@example.com', 'role' => 'owner'], $this->headers())
            ->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/invitations', ['email' => $this->owner->email, 'role' => 'viewer'], $this->headers())
            ->assertJsonValidationErrors('email');
    }

    public function test_inviting_again_replaces_the_previous_link(): void
    {
        $first = $this->invite();
        Notification::fake();
        $second = $this->invite(role: 'finance');

        $this->assertNotSame($first, $second);
        $this->getJson('/api/v1/invitation?'.http_build_query($this->link($first)))->assertNotFound();
        $this->getJson('/api/v1/invitation?'.http_build_query($this->link($second)))->assertJsonPath('data.role', 'finance');
    }

    public function test_the_link_shows_the_invitation_without_an_account(): void
    {
        $token = $this->invite();

        $this->getJson('/api/v1/invitation?'.http_build_query($this->link($token)))
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Kin Events')
            ->assertJsonPath('data.inviter', 'Patrick')
            ->assertJsonPath('data.email', 'grace@example.com')
            ->assertJsonPath('data.expired', false)
            ->assertJsonPath('data.has_account', false);
    }

    public function test_a_wrong_token_or_organization_returns_404(): void
    {
        $token = $this->invite();
        $other = $this->createOrganization(User::factory()->create(), 'Autre');

        $this->getJson('/api/v1/invitation?'.http_build_query($this->link(str_repeat('a', 48))))->assertNotFound();
        $this->getJson('/api/v1/invitation?'.http_build_query(['organization' => $other->id, 'token' => $token]))->assertNotFound();
    }

    public function test_the_invited_person_accepts_and_gets_the_role(): void
    {
        $token = $this->invite();
        Sanctum::actingAs($grace = User::factory()->create(['email' => 'grace@example.com']));

        $this->postJson('/api/v1/invitation/accept', $this->link($token))
            ->assertOk()
            ->assertJsonPath('data.id', $this->organization->id);

        $this->getJson('/api/v1/organization', $this->headers())->assertJsonPath('meta.roles', ['event_manager']);
        $this->getJson('/api/v1/invitation?'.http_build_query($this->link($token)))->assertNotFound();
    }

    public function test_accepting_never_changes_the_role_of_an_existing_member(): void
    {
        $token = app(TenantContext::class)->run($this->organization->id, function () {
            $invitation = new Invitation(['email' => $this->owner->email, 'role' => 'viewer']);
            $token = $invitation->refreshToken();
            $invitation->save();

            return $token;
        });
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/invitation/accept', $this->link($token))->assertOk();
        $this->getJson('/api/v1/organization', $this->headers())->assertJsonPath('meta.roles', ['owner']);
    }

    public function test_another_account_cannot_accept(): void
    {
        $token = $this->invite();
        Sanctum::actingAs(User::factory()->create(['email' => 'intrus@example.com']));

        $this->postJson('/api/v1/invitation/accept', $this->link($token))->assertJsonValidationErrors('token');
        $this->getJson('/api/v1/organization', $this->headers())->assertNotFound();
    }

    public function test_an_expired_invitation_cannot_be_accepted_but_can_be_renewed(): void
    {
        $token = $this->invite();
        $this->travel(8)->days();
        Sanctum::actingAs(User::factory()->create(['email' => 'grace@example.com']));

        $this->getJson('/api/v1/invitation?'.http_build_query($this->link($token)))->assertJsonPath('data.expired', true);
        $this->postJson('/api/v1/invitation/accept', $this->link($token))->assertJsonValidationErrors('token');

        Notification::fake();
        $this->postJson('/api/v1/invitation/renew', $this->link($token))->assertStatus(202);
        $fresh = $this->sentToken('grace@example.com');

        $this->postJson('/api/v1/invitation/accept', $this->link($fresh))->assertOk();
    }

    public function test_a_cancelled_invitation_no_longer_works(): void
    {
        $token = $this->invite();
        $id = $this->getJson('/api/v1/invitations', $this->headers())->json('data.0.id');

        $this->deleteJson("/api/v1/invitations/{$id}", [], $this->headers())->assertNoContent();
        $this->getJson('/api/v1/invitation?'.http_build_query($this->link($token)))->assertNotFound();
    }

    public function test_only_members_who_manage_the_team_can_invite(): void
    {
        $token = $this->invite('agent@example.com', 'check_in_agent');
        Sanctum::actingAs($agent = User::factory()->create(['email' => 'agent@example.com']));
        $this->postJson('/api/v1/invitation/accept', $this->link($token))->assertOk();

        $this->postJson('/api/v1/invitations', ['email' => 'x@example.com', 'role' => 'viewer'], $this->headers())->assertForbidden();
        $this->getJson('/api/v1/members', $this->headers())->assertForbidden();
    }

    public function test_invitations_of_another_organization_are_invisible(): void
    {
        $this->invite();
        $id = app(TenantContext::class)->run($this->organization->id, fn () => Invitation::value('id'));
        $other = $this->createOrganization($stranger = User::factory()->create(), 'Concurrent');
        Sanctum::actingAs($stranger);

        $this->getJson('/api/v1/invitations', ['X-Organization-Id' => $other->id])->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/invitations/{$id}", [], ['X-Organization-Id' => $other->id])->assertNotFound();
        $this->getJson('/api/v1/invitations', $this->headers())->assertNotFound();
    }
}
