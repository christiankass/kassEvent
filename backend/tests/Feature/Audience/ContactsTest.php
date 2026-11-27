<?php

namespace Tests\Feature\Audience;

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private array $event;

    private string $free;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();
        $this->organization = $this->createOrganization($this->owner);
        Sanctum::actingAs($this->owner);
        $this->event = $this->postJson('/api/v1/events', [
            'title' => 'Concert du Fleuve', 'starts_at' => now()->addWeek()->format('Y-m-d').'T19:00',
            'venue_name' => 'Salle du Fleuve', 'city' => 'Kinshasa',
        ], $this->headers())->json('data');
        $this->free = $this->postJson("/api/v1/events/{$this->event['id']}/ticket-types", ['name' => 'Entrée', 'price' => '0', 'quantity' => 100], $this->headers())->json('data.id');
        $this->postJson("/api/v1/events/{$this->event['id']}/publish", [], $this->headers())->assertOk();
        $this->leave();
    }

    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id];
    }

    private function leave(): void
    {
        app(TenantContext::class)->clear();
        auth()->forgetGuards();
    }

    private function buy(string $name, string $email, bool $consent, ?string $whatsapp = null): void
    {
        $this->leave();
        $this->postJson("/api/v1/public/events/{$this->event['slug']}/orders", [
            'items' => [['ticket_type_id' => $this->free, 'quantity' => 1]],
            'name' => $name, 'email' => $email, 'whatsapp' => $whatsapp, 'marketing_consent' => $consent,
        ])->assertCreated();
    }

    private function contacts(array $query = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->owner);

        return $this->getJson('/api/v1/contacts?'.http_build_query($query), $this->headers())->assertOk();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->organization->id, $callback);
    }

    public function test_buyers_join_the_book_once_and_only_those_who_agreed_are_reachable(): void
    {
        $this->buy('Grace Mbuyi', 'Grace@Example.com', false, '0812345678');
        $this->buy('Grace M.', 'grace@example.com', true);
        $this->buy('Patrick Kabongo', 'patrick@example.com', false);

        $response = $this->contacts()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.reachable', 1)
            ->assertJsonPath('summary.with_phone', 1);
        $grace = collect($response->json('data'))->firstWhere('email', 'grace@example.com');
        $this->assertSame('Grace M.', $grace['name']);
        $this->assertSame('243812345678', $grace['phone']);
        $this->assertSame(2, $grace['orders_count']);
        $this->assertTrue($grace['reachable']);
        $this->assertSame('checkout', $grace['consent_source']);

        // Un achat sans la case cochée ne retire pas l'accord déjà donné.
        $this->buy('Grace', 'grace@example.com', false);
        $this->contacts(['filter' => 'reachable'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.orders_count', 3);
        $this->contacts(['filter' => 'no_consent'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'patrick@example.com');
        $this->contacts(['search' => 'kabon'])->assertJsonCount(1, 'data');
        $this->contacts(['search' => '0812'])->assertJsonCount(1, 'data');
    }

    public function test_a_contact_unsubscribes_by_their_personal_link_and_comes_back_only_by_agreeing_again(): void
    {
        $this->buy('Grace', 'grace@example.com', true);
        $contact = $this->inTenant(fn () => Contact::first());
        $url = $contact->unsubscribeUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->leave();

        $this->getJson("/api/v1/public/contacts/{$contact->id}/unsubscribe?key=mauvaise")->assertNotFound();
        $this->getJson("/api/v1/public/contacts/{$contact->id}/unsubscribe?key={$query['cle']}")->assertOk()
            ->assertJsonPath('data.organization', 'Kin Events')->assertJsonPath('data.unsubscribed', false);
        $this->postJson("/api/v1/public/contacts/{$contact->id}/unsubscribe", ['key' => $query['cle']])->assertOk()
            ->assertJsonPath('data.unsubscribed', true);
        $this->contacts()->assertJsonPath('summary.reachable', 0)->assertJsonPath('summary.unsubscribed', 1);

        $this->buy('Grace', 'grace@example.com', false);
        $this->contacts()->assertJsonPath('summary.reachable', 0);
        $this->buy('Grace', 'grace@example.com', true);
        $this->contacts()->assertJsonPath('summary.reachable', 1)->assertJsonPath('summary.unsubscribed', 0);
    }

    public function test_guests_join_the_book_when_they_answer(): void
    {
        Sanctum::actingAs($this->owner);
        $guest = $this->postJson("/api/v1/events/{$this->event['id']}/guests", ['guests' => [
            ['name' => 'Tante Ruth', 'phone' => '0991234567'],
        ]], $this->headers())->assertCreated()->json('data.0');
        parse_str((string) parse_url($guest['rsvp_url'], PHP_URL_QUERY), $query);
        $this->leave();

        $answer = fn (bool $consent) => $this->postJson("/api/v1/public/guests/{$guest['id']}/rsvp", ['key' => $query['cle'], 'attending' => true, 'marketing_consent' => $consent])->assertOk();
        $answer(false);
        $this->contacts()->assertJsonPath('summary.total', 1)->assertJsonPath('summary.reachable', 0)
            ->assertJsonPath('data.0.phone', '243991234567')->assertJsonPath('data.0.invitations_count', 1);
        $this->leave();
        $answer(true);
        $this->contacts()->assertJsonPath('summary.reachable', 1)
            ->assertJsonPath('data.0.consent_source', 'rsvp')->assertJsonPath('data.0.invitations_count', 1);
    }

    public function test_contacts_are_isolated_and_deleted_on_request(): void
    {
        $this->buy('Grace', 'grace@example.com', true);
        $id = $this->contacts()->json('data.0.id');

        $intruder = User::factory()->create();
        $intruder->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();
        $other = $this->createOrganization($intruder, 'Autre');
        Sanctum::actingAs($intruder);
        $this->getJson('/api/v1/contacts', $this->headers($other))->assertOk()->assertJsonPath('summary.total', 0);
        $this->deleteJson("/api/v1/contacts/{$id}", [], $this->headers($other))->assertNotFound();
        $this->getJson('/api/v1/contacts', $this->headers())->assertNotFound();

        $agent = User::factory()->create();
        $this->inTenant(function () use ($agent) {
            $this->organization->users()->attach($agent->id, ['status' => 'active']);
            $agent->assignRole('check_in_agent');
        });
        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/contacts', $this->headers())->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/contacts/{$id}", [], $this->headers())->assertNoContent();
        $this->contacts()->assertJsonPath('summary.total', 0);
    }
}
