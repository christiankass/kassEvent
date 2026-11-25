<?php

namespace Tests\Feature\CheckIn;

use App\Models\User;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $agent;

    private Organization $organization;

    private string $eventId;

    /** @var list<string> codes des billets de l'événement */
    private array $codes;

    private string $otherEventCode;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->agent = $this->member($this->organization, 'check_in_agent', 'Agent Porte A');

        [$this->eventId, $this->codes] = $this->eventWithTickets($this->owner, $this->organization, 'Gala', 3);
        [, $other] = $this->eventWithTickets($this->owner, $this->organization, 'Concert', 1);
        $this->otherEventCode = $other[0];
    }

    private function member(Organization $organization, string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(TenantContext::class)->run($organization->id, function () use ($organization, $user, $role) {
            $organization->users()->attach($user->id, ['status' => Membership::ACTIVE]);
            $user->assignRole($role);
        });

        return $user;
    }

    /** @return array{0: string, 1: list<string>} identifiant de l'événement et codes de ses billets gratuits */
    private function eventWithTickets(User $owner, Organization $organization, string $title, int $count): array
    {
        Sanctum::actingAs($owner);
        $headers = ['X-Organization-Id' => $organization->id];
        $event = $this->postJson('/api/v1/events', [
            'title' => $title, 'starts_at' => now()->addWeek()->format('Y-m-d').'T19:00',
            'venue_name' => 'Hôtel du Fleuve', 'city' => 'Kinshasa',
        ], $headers)->json('data');
        $type = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Invité', 'price' => '0', 'quantity' => 50], $headers)->json('data.id');
        $this->postJson("/api/v1/events/{$event['id']}/publish", [], $headers)->assertOk();
        app(TenantContext::class)->clear();
        auth()->forgetGuards();

        $this->postJson("/api/v1/public/events/{$event['slug']}/orders", [
            'items' => [['ticket_type_id' => $type, 'quantity' => $count]],
            'name' => 'Mireille Kabeya', 'email' => 'm@example.com', 'payment' => null,
        ])->assertCreated();

        $codes = app(TenantContext::class)->run($organization->id, fn () => Ticket::where('event_id', $event['id'])->orderBy('code')->pluck('code')->all());
        app(TenantContext::class)->clear();

        return [$event['id'], $codes];
    }

    private function scan(array $scans, ?User $as = null, ?string $eventId = null)
    {
        Sanctum::actingAs($as ?? $this->agent);

        return $this->postJson('/api/v1/events/'.($eventId ?? $this->eventId).'/check-in/scans', ['scans' => $scans], ['X-Organization-Id' => $this->organization->id]);
    }

    /** Lecture directe des tables sous la RLS de l'organisation. */
    private function inTenant(callable $assertions): void
    {
        app(TenantContext::class)->run($this->organization->id, $assertions);
    }

    private function one(string $code, array $extra = []): array
    {
        return ['id' => (string) Str::uuid7(), 'code' => $code, ...$extra];
    }

    public function test_a_ticket_enters_once_then_shows_the_first_scan(): void
    {
        $this->scan([$this->one($this->codes[0], ['gate' => 'Porte A'])])->assertOk()
            ->assertJsonPath('data.0.result', 'valid')
            ->assertJsonPath('data.0.ticket.holder_name', 'Mireille Kabeya')
            ->assertJsonPath('data.0.ticket.ticket_type', 'Invité')
            ->assertJsonPath('data.0.ticket.companions', 0)
            ->assertJsonPath('data.0.first_scan', null);

        $this->travel(5)->minutes();
        $this->scan([$this->one($this->codes[0])])->assertOk()
            ->assertJsonPath('data.0.result', 'already_used')
            ->assertJsonPath('data.0.first_scan.gate', 'Porte A')
            ->assertJsonPath('data.0.first_scan.agent', 'Agent Porte A');
    }

    public function test_codes_typed_by_hand_are_normalized(): void
    {
        $typed = strtolower(implode('-', str_split($this->codes[1], 4)));

        $this->scan([$this->one(" {$typed} ")])->assertJsonPath('data.0.result', 'valid');
    }

    public function test_unknown_codes_and_tickets_of_another_event_are_refused(): void
    {
        $this->scan([
            $this->one('NOT-A-TICKET'),
            $this->one($this->otherEventCode),
            $this->one(str_repeat('A', 300)),
        ])->assertOk()
            ->assertJsonPath('data.0.result', 'invalid')
            ->assertJsonPath('data.0.ticket', null)
            ->assertJsonPath('data.1.result', 'other_event')
            ->assertJsonPath('data.1.other_event', 'Concert')
            ->assertJsonPath('data.1.ticket', null)
            ->assertJsonPath('data.2.result', 'invalid');
    }

    public function test_the_first_scan_wins_when_an_offline_phone_syncs_late(): void
    {
        $offline = $this->one($this->codes[0], ['scanned_at' => now()->toIso8601ZuluString(), 'gate' => 'Porte B', 'offline' => true]);

        // Dix minutes plus tard, une autre porte, en ligne, accepte le même billet…
        $this->travel(10)->minutes();
        $online = $this->one($this->codes[0], ['gate' => 'Porte A']);
        $this->scan([$online])->assertJsonPath('data.0.result', 'valid');

        // …puis le téléphone de la porte B retrouve le réseau : son scan, plus ancien, fait foi.
        $this->scan([$offline])->assertJsonPath('data.0.result', 'valid');

        // Renvoyer le scan en ligne (réponse perdue) dit maintenant « déjà utilisé » à la porte A.
        $this->scan([$online])->assertJsonPath('data.0.result', 'already_used')
            ->assertJsonPath('data.0.first_scan.gate', 'Porte B');

        Sanctum::actingAs($this->agent);
        $list = $this->getJson("/api/v1/events/{$this->eventId}/check-in", ['X-Organization-Id' => $this->organization->id])->assertOk();
        $this->assertSame(1, $list->json('checked_in'));
        $entry = collect($list->json('tickets'))->firstWhere('hash', hash_pbkdf2('sha256', $this->codes[0], $list->json('fingerprint.salt'), $list->json('fingerprint.iterations')));
        $this->assertSame('Porte B', $entry['first_scan']['gate']);
        $this->inTenant(function () {
            $this->assertDatabaseCount('ticket_check_ins', 1);
            $this->assertDatabaseHas('ticket_check_ins', ['scanned_at' => now()->subMinutes(10)->format('Y-m-d H:i:s')]);
        });
    }

    public function test_offline_duplicates_in_one_batch_keep_the_earliest(): void
    {
        $later = $this->one($this->codes[2], ['scanned_at' => now()->subMinute()->toIso8601ZuluString(), 'offline' => true]);
        $earlier = $this->one($this->codes[2], ['scanned_at' => now()->subMinutes(3)->toIso8601ZuluString(), 'offline' => true]);

        $this->scan([$later, $earlier])->assertJsonPath('data.0.result', 'valid')->assertJsonPath('data.1.result', 'valid');

        // Une fois tout synchronisé, seul le plus ancien est valide.
        $this->scan([$later, $earlier])
            ->assertJsonPath('data.0.result', 'already_used')
            ->assertJsonPath('data.1.result', 'valid');
        $this->inTenant(fn () => $this->assertDatabaseCount('ticket_scans', 2));
    }

    public function test_online_scans_use_the_server_clock_and_offline_times_are_bounded(): void
    {
        // En ligne, l'heure annoncée par le téléphone est ignorée.
        $online = $this->one($this->codes[0], ['scanned_at' => now()->subHours(5)->toIso8601ZuluString()]);
        $this->scan([$online])->assertJsonPath('data.0.result', 'valid');
        $this->inTenant(fn () => $this->assertDatabaseHas('ticket_scans', ['client_id' => $online['id'], 'scanned_at' => now()->format('Y-m-d H:i:s')]));

        // Hors ligne, une horloge très en retard est ramenée à la veille de l'événement : elle gagne
        // contre les scans de la journée, pas contre l'historique.
        $old = $this->one($this->codes[1], ['scanned_at' => '2020-01-01T00:00:00Z', 'offline' => true]);
        $this->scan([$old])->assertJsonPath('data.0.result', 'valid');
        $this->inTenant(fn () => $this->assertDatabaseMissing('ticket_scans', ['client_id' => $old['id'], 'scanned_at' => '2020-01-01 00:00:00']));
    }

    public function test_a_person_refused_offline_does_not_count_as_entered(): void
    {
        // Liste pas à jour : l'agent a refusé le billet hors ligne.
        $refused = $this->one($this->codes[0], ['offline' => true, 'admitted' => false, 'scanned_at' => now()->subMinutes(5)->toIso8601ZuluString()]);
        $this->scan([$refused])->assertJsonPath('data.0.result', 'valid')->assertJsonPath('data.0.admitted', false);

        // La personne revient : elle entre normalement.
        $this->scan([$this->one($this->codes[0])])->assertJsonPath('data.0.result', 'valid');
        $this->scan([$refused])->assertJsonPath('data.0.result', 'already_used');
        $this->inTenant(fn () => $this->assertDatabaseCount('ticket_check_ins', 1));
    }

    public function test_the_same_scan_sent_twice_counts_once(): void
    {
        $scan = $this->one($this->codes[0]);
        $this->scan([$scan])->assertJsonPath('data.0.result', 'valid');
        $this->scan([[...$scan, 'offline' => true]])->assertJsonPath('data.0.result', 'valid');
        $this->inTenant(fn () => $this->assertDatabaseCount('ticket_scans', 1));
    }

    public function test_a_phone_clock_ahead_cannot_win(): void
    {
        $future = $this->one($this->codes[0], ['scanned_at' => now()->addHour()->toIso8601ZuluString(), 'offline' => true]);
        $this->scan([$future])->assertJsonPath('data.0.result', 'valid');

        $this->inTenant(fn () => $this->assertDatabaseHas('ticket_scans', ['client_id' => $future['id'], 'scanned_at' => now()->format('Y-m-d H:i:s')]));
    }

    public function test_the_offline_list_holds_fingerprints_not_codes(): void
    {
        Sanctum::actingAs($this->agent);
        $response = $this->getJson("/api/v1/events/{$this->eventId}/check-in", ['X-Organization-Id' => $this->organization->id])->assertOk()
            ->assertJsonPath('event.title', 'Gala')
            ->assertJsonCount(3, 'tickets')
            ->assertJsonPath('checked_in', 0);

        $this->assertStringNotContainsString($this->codes[0], $response->getContent());
        $salt = $response->json('fingerprint.salt');
        $iterations = $response->json('fingerprint.iterations');
        $this->assertEqualsCanonicalizing(
            array_map(fn ($code) => hash_pbkdf2('sha256', $code, $salt, $iterations), $this->codes),
            array_column($response->json('tickets'), 'hash'),
        );
    }

    public function test_only_members_who_scan_can_check_in(): void
    {
        $viewer = $this->member($this->organization, 'viewer', 'Lecteur');
        $this->scan([$this->one($this->codes[0])], $viewer)->assertForbidden();

        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/events/{$this->eventId}/check-in", ['X-Organization-Id' => $this->organization->id])->assertForbidden();
    }

    public function test_another_organization_sees_nothing(): void
    {
        $stranger = User::factory()->create();
        $theirs = $this->createOrganization($stranger, 'Autre agence');
        [$theirEvent] = $this->eventWithTickets($stranger, $theirs, 'Leur fête', 1);

        // Leur événement est introuvable chez nous…
        $this->scan([$this->one($this->codes[0])], null, $theirEvent)->assertNotFound();
        Sanctum::actingAs($this->agent);
        $this->getJson("/api/v1/events/{$theirEvent}/check-in", ['X-Organization-Id' => $this->organization->id])->assertNotFound();

        // …et nos billets sont invalides chez eux, sans rien révéler.
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/events/{$theirEvent}/check-in/scans", ['scans' => [$this->one($this->codes[0])]], ['X-Organization-Id' => $theirs->id])
            ->assertOk()->assertJsonPath('data.0.result', 'invalid')->assertJsonPath('data.0.ticket', null);
    }

    public function test_scans_are_validated(): void
    {
        $this->scan([['id' => 'pas-un-uuid', 'code' => 'X']])->assertUnprocessable()->assertJsonValidationErrors('scans.0.id');
        $this->scan(array_fill(0, 201, $this->one('X')))->assertUnprocessable();
    }
}
