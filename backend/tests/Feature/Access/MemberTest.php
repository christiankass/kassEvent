<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->createOrganization($this->owner = User::factory()->create(['name' => 'Patrick']));
    }

    private function addMember(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(TenantContext::class)->run($this->organization->id, function () use ($user, $role) {
            $this->organization->users()->attach($user->id, ['status' => Membership::ACTIVE]);
            $user->assignRole($role);
        });

        return $user;
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    public function test_the_team_lists_members_with_their_role(): void
    {
        $this->addMember('finance', ['name' => 'Aline']);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/members', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Aline')
            ->assertJsonPath('data.0.role', 'finance')
            ->assertJsonPath('data.1.role', 'owner')
            ->assertJsonPath('meta.assignable_roles', ['admin', 'event_manager', 'finance', 'check_in_agent', 'viewer']);
    }

    public function test_the_owner_changes_a_role(): void
    {
        $member = $this->addMember('viewer');
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/v1/members/{$member->id}", ['role' => 'admin'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
        // Liste triée par nom : on retrouve le membre par son identifiant.
        $listed = collect($this->getJson('/api/v1/members', $this->headers())->json('data'))->firstWhere('id', $member->id);
        $this->assertSame('admin', $listed['role']);

        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/v1/organization', $this->headers())->assertJsonPath('meta.roles', ['admin']);
    }

    public function test_a_removed_member_loses_access(): void
    {
        $member = $this->addMember('event_manager');
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/members/{$member->id}", [], $this->headers())->assertNoContent();

        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/v1/organization', $this->headers())->assertNotFound();
    }

    public function test_the_owner_and_oneself_are_protected(): void
    {
        $admin = $this->addMember('admin');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/members/{$this->owner->id}", ['role' => 'viewer'], $this->headers())->assertJsonValidationErrors('member');
        $this->deleteJson("/api/v1/members/{$this->owner->id}", [], $this->headers())->assertJsonValidationErrors('member');
        $this->deleteJson("/api/v1/members/{$admin->id}", [], $this->headers())->assertJsonValidationErrors('member');
        $this->patchJson("/api/v1/members/{$admin->id}", ['role' => 'owner'], $this->headers())->assertJsonValidationErrors('role');
    }

    public function test_an_admin_cannot_give_a_role_with_more_rights_than_their_own(): void
    {
        $admin = $this->addMember('admin');
        $member = $this->addMember('viewer');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/members', $this->headers())
            ->assertJsonPath('meta.assignable_roles', ['admin', 'event_manager', 'check_in_agent', 'viewer']);
        $this->patchJson("/api/v1/members/{$member->id}", ['role' => 'finance'], $this->headers())->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/invitations', ['email' => 'moi-bis@example.com', 'role' => 'finance'], $this->headers())
            ->assertJsonValidationErrors('role');
    }

    public function test_a_member_of_another_organization_returns_404(): void
    {
        $other = $this->createOrganization($stranger = User::factory()->create(), 'Autre');
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/v1/members/{$stranger->id}", ['role' => 'viewer'], $this->headers())->assertNotFound();
        $this->deleteJson('/api/v1/members/not-a-uuid', [], $this->headers())->assertNotFound();
    }
}
