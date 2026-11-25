<?php

namespace Tests\Feature\Guests;

use App\Models\User;
use App\Modules\Guests\Jobs\SendGuestInvitation;
use App\Modules\Guests\Mail\GuestInvitationMail;
use App\Modules\Guests\Models\Guest;
use App\Modules\Guests\Support\SeatingLayout;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Mail\TicketsMail;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private string $eventId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner, 'Mariage Kabeya', 'individual');
        $this->actAsOwner();
        $this->eventId = $this->postJson('/api/v1/events', [
            'title' => 'Mariage de Mireille et Patrick', 'starts_at' => now()->addMonth()->format('Y-m-d').'T17:00',
            'venue_name' => 'Salle Le Fleuve', 'city' => 'Kinshasa',
        ], $this->headers())->assertCreated()->json('data.id');
    }

    private function actAsOwner(): void
    {
        app(TenantContext::class)->clear();
        Sanctum::actingAs($this->owner);
    }

    /** @return array<string, string> */
    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id];
    }

    /** @return list<array<string, mixed>> */
    private function addGuests(array $guests): array
    {
        $this->actAsOwner();

        return $this->postJson("/api/v1/events/{$this->eventId}/guests", ['guests' => $guests], $this->headers())->assertCreated()->json('data');
    }

    private function guestsPage(): array
    {
        $this->actAsOwner();

        return $this->getJson("/api/v1/events/{$this->eventId}/guests", $this->headers())->assertOk()->json();
    }

    /** Lien personnel de l'invité : identifiant et clé. */
    private function link(array $guest): array
    {
        parse_str((string) parse_url($guest['rsvp_url'], PHP_URL_QUERY), $query);

        return [$guest['id'], $query['cle']];
    }

    private function answer(array $guest, bool $attending, int $companions = 0)
    {
        [$id, $key] = $this->link($guest);
        app(TenantContext::class)->clear();
        auth()->forgetGuards();

        return $this->postJson("/api/v1/public/guests/{$id}/rsvp", ['key' => $key, 'attending' => $attending, 'companions' => $companions]);
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->organization->id, $callback);
    }

    public function test_guests_are_added_by_hand_or_imported_and_counted(): void
    {
        $guests = $this->addGuests([
            ['name' => ' Papa Jean ', 'phone' => '0812345678', 'companions_allowed' => 1, 'group_name' => 'Famille du marié'],
            ['name' => 'Tante Brigitte', 'email' => 'Brigitte@Example.com', 'companions_allowed' => 3],
            ['name' => 'Cousin Éric', 'phone' => '+33 6 12 34 56 78'],
        ]);

        $this->assertSame(['Papa Jean', '243812345678'], [$guests[0]['name'], $guests[0]['phone']]);
        $this->assertSame('brigitte@example.com', $guests[1]['email']);
        $this->assertSame('33612345678', $guests[2]['phone']);
        $this->assertStringContainsString('/rsvp/'.$guests[0]['id'].'?cle=', $guests[0]['rsvp_url']);

        $page = $this->guestsPage();
        $this->assertSame(3, $page['summary']['pending']);
        $this->assertSame(2 + 4 + 1, $page['summary']['people_expected']);
        $this->assertSame(3, $page['summary']['not_invited']);

        $this->postJson("/api/v1/events/{$this->eventId}/guests", ['guests' => [['name' => 'X', 'phone' => '12']]], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('guests.0.phone');
    }

    public function test_the_hall_capacity_gives_the_tables_and_seats(): void
    {
        $this->assertSame([10, 10, 10, 10, 10, 9, 9, 9, 9, 9], SeatingLayout::seats(95, 10));
        $this->assertSame([13, 13, 13, 13, 12, 12, 12, 12], SeatingLayout::seats(100, tableCount: 8));
        $this->assertSame([10, 10, 10], SeatingLayout::seats(30));

        $tables = $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 95], $this->headers())->assertOk()->json('tables');
        $this->assertCount(10, $tables);
        $this->assertSame(95, array_sum(array_column($tables, 'seats')));
        $this->assertSame('Table 1', $tables[0]['label']);

        // Moins de tables : les tables en trop sont retirées, les autres gardent leur nom.
        $this->patchJson("/api/v1/tables/{$tables[0]['id']}", ['name' => 'Honneur'], $this->headers())->assertOk()->assertJsonPath('data.label', 'Table 1 · Honneur');
        $tables = $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 48, 'table_count' => 4], $this->headers())->assertOk()->json('tables');
        $this->assertSame([12, 12, 12, 12], array_column($tables, 'seats'));
        $this->assertSame('Honneur', $tables[0]['name']);
        $this->assertSame(['hall_capacity' => 48, 'seats_per_table' => 12], $this->guestsPage()['plan']);

        $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 100, 'seats_per_table' => 10, 'table_count' => 5], $this->headers())->assertUnprocessable();
        $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 500, 'table_count' => 2], $this->headers())->assertJsonValidationErrors('table_count');
    }

    public function test_a_table_never_takes_more_people_than_its_seats(): void
    {
        $tables = $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 8, 'seats_per_table' => 4], $this->headers())->json('tables');
        [$family, $friend, $late] = $this->addGuests([
            ['name' => 'Famille Mbuyi', 'companions_allowed' => 2],
            ['name' => 'Ami Joël'],
            ['name' => 'Oncle Paul', 'companions_allowed' => 1],
        ]);

        $this->putJson("/api/v1/guests/{$family['id']}/table", ['table_id' => $tables[0]['id']], $this->headers())->assertOk()->assertJsonPath('data.table_id', $tables[0]['id']);
        $this->putJson("/api/v1/guests/{$friend['id']}/table", ['table_id' => $tables[0]['id']], $this->headers())->assertOk();
        $this->putJson("/api/v1/guests/{$late['id']}/table", ['table_id' => $tables[0]['id']], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('table_id');
        // Plus d'accompagnants permis qu'il ne reste de places à sa table : refusé aussi.
        $this->patchJson("/api/v1/guests/{$friend['id']}", ['companions_allowed' => 1], $this->headers())->assertJsonValidationErrors('companions_allowed');
        // Ni moins de places que de personnes déjà placées.
        $this->patchJson("/api/v1/tables/{$tables[0]['id']}", ['seats' => 3], $this->headers())->assertJsonValidationErrors('seats');

        $this->assertSame(4, $this->guestsPage()['tables'][0]['occupied']);

        // Un invité qui décline libère sa place et ne peut plus être placé.
        $this->answer($friend, false)->assertOk()->assertJsonPath('data.guest.status', 'declined');
        $this->actAsOwner();
        $this->assertSame(3, $this->guestsPage()['tables'][0]['occupied']);
        $this->putJson("/api/v1/guests/{$friend['id']}/table", ['table_id' => $tables[1]['id']], $this->headers())->assertJsonValidationErrors('table_id');
    }

    public function test_guests_are_placed_automatically_with_their_group(): void
    {
        $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 12, 'seats_per_table' => 6], $this->headers());
        $this->addGuests([
            ['name' => 'A', 'group_name' => 'Mariée', 'companions_allowed' => 3],
            ['name' => 'B', 'group_name' => 'Marié', 'companions_allowed' => 3],
            ['name' => 'C', 'group_name' => 'Mariée', 'companions_allowed' => 1],
            ['name' => 'D', 'group_name' => 'Marié', 'companions_allowed' => 1],
            ['name' => 'E', 'companions_allowed' => 9],
        ]);

        $this->postJson("/api/v1/events/{$this->eventId}/seating/auto", [], $this->headers())->assertOk()->assertExactJson(['placed' => 4, 'unplaced' => 1]);

        $page = $this->guestsPage();
        $tableOf = collect($page['data'])->pluck('table_id', 'name');
        $this->assertSame($tableOf['A'], $tableOf['C']);
        $this->assertSame($tableOf['B'], $tableOf['D']);
        $this->assertNotSame($tableOf['A'], $tableOf['B']);
        $this->assertNull($tableOf['E']);
        $this->assertSame(1, $page['summary']['unseated']);
    }

    public function test_a_guest_answers_and_receives_a_scannable_invitation_with_the_table(): void
    {
        $tables = $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 20], $this->headers())->json('tables');
        [$guest] = $this->addGuests([['name' => 'Tante Brigitte', 'email' => 'brigitte@example.com', 'companions_allowed' => 2]]);
        $this->putJson("/api/v1/guests/{$guest['id']}/table", ['table_id' => $tables[1]['id']], $this->headers())->assertOk();
        [$id, $key] = $this->link($guest);

        app(TenantContext::class)->clear();
        auth()->forgetGuards();
        $this->getJson("/api/v1/public/guests/{$id}?key=wrong")->assertNotFound();
        $this->getJson("/api/v1/public/guests/{$id}?key={$key}")->assertOk()
            ->assertJsonPath('data.guest.name', 'Tante Brigitte')
            ->assertJsonPath('data.guest.status', 'pending')
            ->assertJsonPath('data.event.title', 'Mariage de Mireille et Patrick')
            ->assertJsonPath('data.invitation_url', null);

        // Plus d'accompagnants que permis : ramené au maximum.
        $url = $this->answer($guest, true, 5)->assertOk()
            ->assertJsonPath('data.guest.status', 'confirmed')
            ->assertJsonPath('data.guest.companions_confirmed', 2)
            ->assertJsonPath('data.guest.table', 'Table 2')
            ->json('data.invitation_url');
        $this->assertStringContainsString('/commande/', $url);
        Mail::assertSent(TicketsMail::class, fn (TicketsMail $mail) => $mail->hasTo('brigitte@example.com'));

        $ticket = $this->inTenant(fn () => Ticket::with('item', 'order')->sole());
        $this->assertSame([2, 'Table 2', 'Invitation +2 · Table 2', Order::INVITATION], [$ticket->companions, $ticket->seat, $ticket->label(), $ticket->order->source]);

        // Changement de table : imprimé sur l'invitation et vu au scan.
        $this->actAsOwner();
        $this->putJson("/api/v1/guests/{$guest['id']}/table", ['table_id' => $tables[0]['id']], $this->headers())->assertOk();
        $scan = fn () => $this->postJson("/api/v1/events/{$this->eventId}/check-in/scans", ['scans' => [['id' => (string) Str::uuid7(), 'code' => $ticket->code]]], $this->headers());
        $scan()->assertOk()->assertJsonPath('data.0.result', 'valid')
            ->assertJsonPath('data.0.ticket.companions', 2)
            ->assertJsonPath('data.0.ticket.seat', 'Table 1');

        // Les invitations ne sont pas des ventes.
        $manager = User::factory()->create();
        $this->inTenant(function () use ($manager) {
            $this->organization->users()->attach($manager->id, ['status' => 'active']);
            $manager->assignRole('event_manager');
        });
        app(TenantContext::class)->clear();
        Sanctum::actingAs($manager);
        $this->getJson("/api/v1/events/{$this->eventId}/orders", $this->headers())->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.orders', 0);
    }

    public function test_a_guest_who_declines_after_confirming_cannot_enter_until_confirming_again(): void
    {
        [$guest] = $this->addGuests([['name' => 'Ami Joël', 'phone' => '0991234567']]);
        $this->answer($guest, true)->assertOk();
        $code = $this->inTenant(fn () => Ticket::sole()->code);

        $this->answer($guest, false)->assertOk()->assertJsonPath('data.invitation_url', null);
        $this->actAsOwner();
        $list = $this->getJson("/api/v1/events/{$this->eventId}/check-in", $this->headers())->assertOk();
        $this->assertCount(0, $list->json('tickets'));
        $this->postJson("/api/v1/events/{$this->eventId}/check-in/scans", ['scans' => [['id' => (string) Str::uuid7(), 'code' => $code]]], $this->headers())
            ->assertJsonPath('data.0.result', 'invalid');

        $this->answer($guest, true)->assertOk()->assertJsonPath('data.guest.status', 'confirmed');
        $this->assertSame([$code], $this->inTenant(fn () => Ticket::pluck('code')->all()));
        $this->actAsOwner();
        $this->postJson("/api/v1/events/{$this->eventId}/check-in/scans", ['scans' => [['id' => (string) Str::uuid7(), 'code' => $code]]], $this->headers())
            ->assertJsonPath('data.0.result', 'valid');
    }

    public function test_replies_close_when_the_event_starts(): void
    {
        [$guest] = $this->addGuests([['name' => 'Retardataire']]);
        $this->travel(32)->days();

        $this->answer($guest, true)->assertUnprocessable()->assertJsonValidationErrors('attending');
    }

    public function test_invitations_go_by_email_at_most_once_a_day(): void
    {
        Queue::fake();
        $this->addGuests([
            ['name' => 'Avec e-mail', 'email' => 'a@example.com'],
            ['name' => 'Sans e-mail', 'phone' => '0812345678'],
        ]);

        $this->postJson("/api/v1/events/{$this->eventId}/guests/email", ['pending' => true], $this->headers())->assertOk()->assertExactJson(['sent' => 1, 'selected' => 2]);
        Queue::assertPushed(SendGuestInvitation::class, 1);
        $this->postJson("/api/v1/events/{$this->eventId}/guests/email", ['pending' => true], $this->headers())->assertExactJson(['sent' => 0, 'selected' => 2]);

        $this->travel(25)->hours();
        $this->postJson("/api/v1/events/{$this->eventId}/guests/email", ['pending' => true], $this->headers())->assertExactJson(['sent' => 1, 'selected' => 2]);

        // Envoyée par l'organisateur sur WhatsApp, depuis son téléphone.
        $other = collect($this->guestsPage()['data'])->firstWhere('name', 'Sans e-mail');
        $this->postJson("/api/v1/guests/{$other['id']}/sent", ['channel' => 'whatsapp'], $this->headers())->assertOk()->assertJsonPath('data.can_remind', false);
        $this->assertSame(0, $this->guestsPage()['summary']['not_invited']);
    }

    public function test_the_invitation_email_carries_the_personal_link(): void
    {
        [$guest] = $this->addGuests([['name' => 'Avec e-mail', 'email' => 'a@example.com', 'companions_allowed' => 1]]);
        $this->postJson("/api/v1/events/{$this->eventId}/guests/email", ['guest_ids' => [$guest['id']]], $this->headers())->assertExactJson(['sent' => 1, 'selected' => 1]);

        Mail::assertSent(GuestInvitationMail::class, function ($mail) use ($guest) {
            $mail->assertSeeInHtml(e($guest['rsvp_url']), false);

            return $mail->hasTo('a@example.com');
        });
    }

    public function test_another_organization_cannot_see_or_change_the_guests(): void
    {
        $tables = $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 10], $this->headers())->json('tables');
        [$guest] = $this->addGuests([['name' => 'Papa Jean']]);

        $intruder = User::factory()->create();
        $other = $this->createOrganization($intruder, 'Autre agence');
        app(TenantContext::class)->clear();
        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/events/{$this->eventId}/guests", $this->headers($other))->assertNotFound();
        $this->patchJson("/api/v1/guests/{$guest['id']}", ['name' => 'Piraté'], $this->headers($other))->assertNotFound();
        $this->deleteJson("/api/v1/guests/{$guest['id']}", [], $this->headers($other))->assertNotFound();
        $this->putJson("/api/v1/guests/{$guest['id']}/table", ['table_id' => $tables[0]['id']], $this->headers($other))->assertNotFound();
        $this->patchJson("/api/v1/tables/{$tables[0]['id']}", ['seats' => 2], $this->headers($other))->assertNotFound();
        $this->putJson("/api/v1/events/{$this->eventId}/seating", ['hall_capacity' => 10], $this->headers($other))->assertNotFound();

        $this->assertSame('Papa Jean', $this->inTenant(fn () => Guest::sole()->name));
    }

    public function test_a_check_in_agent_cannot_manage_the_guest_list(): void
    {
        $agent = User::factory()->create();
        $this->inTenant(function () use ($agent) {
            $this->organization->users()->attach($agent->id, ['status' => 'active']);
            $agent->assignRole('check_in_agent');
        });
        app(TenantContext::class)->clear();
        Sanctum::actingAs($agent);

        $this->postJson("/api/v1/events/{$this->eventId}/guests", ['guests' => [['name' => 'X']]], $this->headers())->assertForbidden();
        $this->getJson("/api/v1/events/{$this->eventId}/guests", $this->headers())->assertOk()->assertJsonPath('data', []);
    }

    public function test_removing_a_guest_cancels_their_invitation(): void
    {
        [$guest] = $this->addGuests([['name' => 'Ami Joël']]);
        $this->answer($guest, true)->assertOk();
        $this->actAsOwner();

        $this->deleteJson("/api/v1/guests/{$guest['id']}", [], $this->headers())->assertNoContent();
        $this->assertSame(Order::CANCELLED, $this->inTenant(fn () => Order::sole()->status));
    }
}
