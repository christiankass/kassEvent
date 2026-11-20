<?php

namespace Tests\Feature\Events;

use App\Models\User;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->createOrganization($this->owner = User::factory()->create());
        Sanctum::actingAs($this->owner);
    }

    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id];
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Concert de Noël',
            'description' => 'Une soirée au fleuve.',
            'starts_at' => now()->addMonth()->format('Y-m-d').'T20:00',
            'venue_name' => 'Salle du Fleuve',
            'city' => 'Kinshasa',
            'capacity' => 500,
            ...$overrides,
        ];
    }

    private function createEvent(array $overrides = []): string
    {
        return $this->postJson('/api/v1/events', $this->payload($overrides), $this->headers())
            ->assertCreated()->json('data.id');
    }

    private function addTicket(string $eventId, array $overrides = []): string
    {
        return $this->postJson("/api/v1/events/{$eventId}/ticket-types", [
            'name' => 'Standard', 'price' => '25', 'quantity' => 100, ...$overrides,
        ], $this->headers())->assertCreated()->json('data.id');
    }

    public function test_an_organizer_creates_a_draft_event_in_local_time(): void
    {
        $date = now()->addMonth()->format('Y-m-d');

        $response = $this->postJson('/api/v1/events', $this->payload(), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.timezone', 'Africa/Kinshasa')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.starts_at', "{$date}T20:00")
            ->assertJsonPath('data.starts_at_utc', "{$date}T19:00:00+00:00");

        $this->assertMatchesRegularExpression('/^concert-de-noel-[a-z0-9]{6}$/', $response->json('data.slug'));
    }

    public function test_validation_errors_are_reported_per_field(): void
    {
        $this->postJson('/api/v1/events', ['title' => 'A', 'starts_at' => 'demain'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'starts_at', 'venue_name', 'city']);
    }

    public function test_ticket_prices_are_stored_in_minor_units(): void
    {
        $event = $this->createEvent();

        $this->postJson("/api/v1/events/{$event}/ticket-types", ['name' => 'VIP', 'price' => '49,99', 'quantity' => 20], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.price', 4999)
            ->assertJsonPath('data.free', false);

        $this->postJson("/api/v1/events/{$event}/ticket-types", ['name' => 'Invité', 'price' => '0', 'quantity' => 20], $this->headers())
            ->assertJsonPath('data.free', true);

        $this->postJson("/api/v1/events/{$event}/ticket-types", ['name' => 'X', 'price' => '-5', 'quantity' => 1], $this->headers())
            ->assertJsonValidationErrors('price');
    }

    public function test_tickets_cannot_exceed_the_venue_capacity(): void
    {
        $event = $this->createEvent(['capacity' => 150]);
        $ticket = $this->addTicket($event, ['quantity' => 100]);

        $this->postJson("/api/v1/events/{$event}/ticket-types", ['name' => 'VIP', 'price' => '50', 'quantity' => 60], $this->headers())
            ->assertJsonValidationErrors('quantity');

        $this->patchJson("/api/v1/ticket-types/{$ticket}", ['name' => 'Standard', 'price' => '25', 'quantity' => 150], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.quantity', 150);
    }

    public function test_publishing_needs_a_ticket_type(): void
    {
        $event = $this->createEvent();

        $this->postJson("/api/v1/events/{$event}/publish", [], $this->headers())
            ->assertJsonValidationErrors('ticket_types');

        $this->addTicket($event);

        $this->postJson("/api/v1/events/{$event}/publish", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_a_past_event_cannot_be_published(): void
    {
        $event = $this->createEvent(['starts_at' => now()->subDay()->format('Y-m-d\TH:i')]);
        $this->addTicket($event);

        $this->postJson("/api/v1/events/{$event}/publish", [], $this->headers())
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_the_public_page_shows_only_published_events(): void
    {
        $event = $this->createEvent();
        $slug = $this->getJson("/api/v1/events/{$event}", $this->headers())->json('data.slug');
        $this->addTicket($event, ['price' => '10']);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/events/{$slug}")->assertNotFound();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/events/{$event}/publish", [], $this->headers())->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/public/events/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Concert de Noël')
            ->assertJsonPath('data.organization.name', $this->organization->name)
            ->assertJsonPath('data.ticket_types.0.price', 1000)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.capacity');
    }

    public function test_a_published_event_cannot_be_deleted_and_keeps_one_ticket(): void
    {
        $event = $this->createEvent();
        $ticket = $this->addTicket($event);
        $this->postJson("/api/v1/events/{$event}/publish", [], $this->headers())->assertOk();

        $this->deleteJson("/api/v1/ticket-types/{$ticket}", [], $this->headers())->assertJsonValidationErrors('ticket_type');
        $this->deleteJson("/api/v1/events/{$event}", [], $this->headers())->assertJsonValidationErrors('event');

        $this->postJson("/api/v1/events/{$event}/unpublish", [], $this->headers())->assertJsonPath('data.status', 'draft');
        $this->deleteJson("/api/v1/events/{$event}", [], $this->headers())->assertNoContent();
        $this->getJson("/api/v1/events/{$event}", $this->headers())->assertNotFound();
    }

    public function test_events_of_another_organization_are_not_found(): void
    {
        $other = $this->createOrganization($stranger = User::factory()->create(), 'Autre');
        Sanctum::actingAs($stranger);
        $foreign = $this->postJson('/api/v1/events', $this->payload(), $this->headers($other))->json('data.id');
        $foreignTicket = $this->addTicketAs($other, $foreign);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/events', $this->headers())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/events/{$foreign}", $this->headers())->assertNotFound();
        $this->patchJson("/api/v1/events/{$foreign}", $this->payload(), $this->headers())->assertNotFound();
        $this->postJson("/api/v1/events/{$foreign}/publish", [], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/events/{$foreign}/ticket-types", ['name' => 'X', 'price' => '1', 'quantity' => 1], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/ticket-types/{$foreignTicket}", [], $this->headers())->assertNotFound();
    }

    private function addTicketAs(Organization $organization, string $eventId): string
    {
        return $this->postJson("/api/v1/events/{$eventId}/ticket-types", ['name' => 'S', 'price' => '1', 'quantity' => 1], $this->headers($organization))
            ->json('data.id');
    }

    public function test_a_viewer_reads_events_but_cannot_change_them(): void
    {
        $event = $this->createEvent();
        $viewer = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($viewer) {
            $this->organization->users()->attach($viewer->id, ['status' => Membership::ACTIVE]);
            $viewer->assignRole('viewer');
        });
        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/events/{$event}", $this->headers())->assertOk();
        $this->postJson('/api/v1/events', $this->payload(), $this->headers())->assertForbidden();
        $this->postJson("/api/v1/events/{$event}/publish", [], $this->headers())->assertForbidden();
    }

    public function test_a_cover_image_is_stored_per_organization(): void
    {
        Storage::fake('public');
        $event = $this->createEvent();

        $path = $this->post("/api/v1/events/{$event}/cover", [
            'cover' => UploadedFile::fake()->image('affiche.jpg', 1200, 630),
        ], [...$this->headers(), 'Accept' => 'application/json'])->assertOk()->json('data.cover_url');

        $this->assertStringContainsString("events/{$this->organization->id}/", $path);

        $this->post("/api/v1/events/{$event}/cover", [
            'cover' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php'),
        ], [...$this->headers(), 'Accept' => 'application/json'])->assertJsonValidationErrors('cover');
    }

    public function test_prices_and_capacity_stay_consistent_when_the_event_changes(): void
    {
        $event = $this->createEvent(['capacity' => 300]);
        $this->addTicket($event, ['quantity' => 200]);

        $this->patchJson("/api/v1/events/{$event}", $this->payload(['capacity' => 100]), $this->headers())
            ->assertJsonValidationErrors('capacity');
        $this->patchJson("/api/v1/events/{$event}", $this->payload(['currency' => 'CDF']), $this->headers())
            ->assertJsonValidationErrors('currency');
        $this->patchJson("/api/v1/events/{$event}", $this->payload(['capacity' => 200]), $this->headers())
            ->assertOk();
    }

    public function test_sales_windows_keep_their_local_time_when_the_timezone_changes(): void
    {
        $event = $this->createEvent();
        $end = now()->addWeeks(2)->format('Y-m-d').'T18:00';
        $this->addTicket($event, ['sales_end_at' => $end]);

        $this->patchJson("/api/v1/events/{$event}", $this->payload(['timezone' => 'Africa/Lubumbashi']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.ticket_types.0.sales_end_at', $end);
    }

    public function test_congolese_franc_prices_have_no_cents(): void
    {
        $event = $this->createEvent(['currency' => 'CDF']);

        $this->postJson("/api/v1/events/{$event}/ticket-types", ['name' => 'S', 'price' => '1500,50', 'quantity' => 10], $this->headers())
            ->assertJsonValidationErrors('price');
        $this->postJson("/api/v1/events/{$event}/ticket-types", ['name' => 'S', 'price' => '15000', 'quantity' => 10], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.price', 1500000);
    }

    public function test_deleting_a_draft_removes_its_tickets(): void
    {
        $event = $this->createEvent();
        $ticket = $this->addTicket($event);

        $this->deleteJson("/api/v1/events/{$event}", [], $this->headers())->assertNoContent();
        $this->patchJson("/api/v1/ticket-types/{$ticket}", ['name' => 'S', 'price' => '1', 'quantity' => 1], $this->headers())
            ->assertNotFound();
    }
}
