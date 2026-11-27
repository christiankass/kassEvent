<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Models\EventVisit;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private array $event;

    private string $paid;

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
            'venue_name' => 'Stade des Martyrs', 'city' => 'Kinshasa', 'capacity' => 100,
        ], $this->headers())->json('data');
        $this->paid = $this->postJson("/api/v1/events/{$this->event['id']}/ticket-types", ['name' => 'Standard', 'price' => '20', 'quantity' => 50], $this->headers())->json('data.id');
        $this->free = $this->postJson("/api/v1/events/{$this->event['id']}/ticket-types", ['name' => 'Invité', 'price' => '0', 'quantity' => 50], $this->headers())->json('data.id');
        $this->postJson("/api/v1/events/{$this->event['id']}/publish", [], $this->headers())->assertOk();
        $this->leave();
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    private function leave(): void
    {
        app(TenantContext::class)->clear();
        auth()->forgetGuards();
    }

    private function visit(?string $source): void
    {
        $this->postJson("/api/v1/public/events/{$this->event['slug']}/visits", ['source' => $source])->assertNoContent();
    }

    /** Commande sur la page publique, payée (mobile money simulé validé quelques secondes plus tard). */
    private function buy(string $ticket, int $quantity, ?array $payment, ?string $referral, string $name = 'Acheteur'): array
    {
        $order = $this->postJson("/api/v1/public/events/{$this->event['slug']}/orders", [
            'items' => [['ticket_type_id' => $ticket, 'quantity' => $quantity]],
            'name' => $name, 'email' => 'a@example.com', 'payment' => $payment, 'referral' => $referral,
        ])->assertCreated();
        $this->travel(10)->seconds();

        return $this->getJson("/api/v1/public/orders/{$order->json('data.id')}?key={$order->json('access_key')}")->json('data');
    }

    private function stats()
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson("/api/v1/events/{$this->event['id']}/stats", $this->headers())->assertOk();
    }

    public function test_the_organizer_sees_where_sales_come_from(): void
    {
        $this->visit('whatsapp');
        $this->visit('whatsapp');
        $this->visit('WhatsApp');
        $this->visit('facebook');
        $this->visit(null);
        $this->visit('un-site-inconnu');

        $this->buy($this->paid, 2, ['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0841234567'], 'whatsapp');
        $this->buy($this->paid, 1, ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0811234567'], 'facebook');
        $this->buy($this->free, 3, null, null);
        // Commande jamais payée : ni dans les ventes, ni dans les sources.
        $this->postJson("/api/v1/public/events/{$this->event['slug']}/orders", [
            'items' => [['ticket_type_id' => $this->paid, 'quantity' => 1]],
            'name' => 'Indécis', 'email' => 'b@example.com', 'payment' => ['method' => 'card'], 'referral' => 'tiktok',
        ])->assertCreated();

        $stats = $this->stats()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.summary.revenue', 6000)
            ->assertJsonPath('data.summary.orders', 3)
            ->assertJsonPath('data.summary.tickets_sold', 6)
            ->assertJsonPath('data.summary.capacity', 100)
            ->assertJsonPath('data.summary.visits', 6)
            ->assertJsonPath('data.summary.conversion', 0.5)
            ->assertJsonPath('data.summary.average_order', 3000)
            ->assertJsonPath('data.summary.tickets_issued', 6)
            ->assertJsonPath('data.ticket_types.0.sold', 3)
            ->assertJsonPath('data.ticket_types.0.revenue', 6000)
            ->assertJsonPath('data.ticket_types.1.sold', 3)
            ->assertJsonPath('data.url', config('app.frontend_url').'/e/'.$this->event['slug'])
            ->json('data');

        $sources = collect($stats['sources'])->keyBy('source');
        $this->assertSame(['visits' => 3, 'orders' => 1, 'tickets' => 2, 'revenue' => 4000], collect($sources['whatsapp'])->except('source')->all());
        $this->assertSame(['visits' => 1, 'orders' => 1, 'tickets' => 1, 'revenue' => 2000], collect($sources['facebook'])->except('source')->all());
        $this->assertSame(['visits' => 1, 'orders' => 1, 'tickets' => 3, 'revenue' => 0], collect($sources['direct'])->except('source')->all());
        $this->assertSame(1, $sources['other']['visits']);
        $this->assertArrayNotHasKey('tiktok', $sources->all());
        $this->assertSame('whatsapp', $stats['sources'][0]['source']);

        $payments = collect($stats['payments'])->keyBy(fn ($row) => $row['method'].'/'.$row['operator']);
        $this->assertSame(4000, $payments['mobile_money/orange']['revenue']);
        $this->assertSame(1, $payments['mobile_money/vodacom']['orders']);
        $this->assertSame(1, $payments['free/']['orders']);

        $this->assertCount(1, $stats['daily']);
        $this->assertSame(['orders' => 3, 'tickets' => 6, 'revenue' => 6000, 'visits' => 6], collect($stats['daily'][0])->except('date')->all());
        $this->assertStringContainsString('<svg', $stats['qr_svg']);
    }

    public function test_days_without_sales_are_in_the_curve_and_days_follow_the_venue_time(): void
    {
        // 23 h 30 UTC = 0 h 30 le lendemain à Kinshasa (UTC+1).
        $this->travelTo(now()->setTime(23, 30));
        $this->buy($this->paid, 1, ['method' => 'mobile_money', 'operator' => 'airtel', 'phone' => '0991234567'], null);
        $first = now('Africa/Kinshasa')->toDateString();
        $this->travel(3)->days();
        $this->visit('qr');

        $daily = $this->stats()->json('data.daily');

        $this->assertCount(4, $daily);
        $this->assertSame($first, $daily[0]['date']);
        $this->assertSame(1, $daily[0]['orders']);
        $this->assertSame(0, $daily[1]['orders'] + $daily[1]['visits']);
        $this->assertSame(1, $daily[3]['visits']);
    }

    public function test_entries_and_attendance_include_companions(): void
    {
        $order = $this->buy($this->free, 4, null, null);
        Sanctum::actingAs($this->owner);
        foreach (array_slice($order['tickets'], 0, 3) as $ticket) {
            $this->postJson("/api/v1/events/{$this->event['id']}/check-in/scans", ['scans' => [['id' => (string) Str::uuid7(), 'code' => $ticket['code']]]], $this->headers())->assertOk();
        }

        $this->stats()
            ->assertJsonPath('data.summary.tickets_issued', 4)
            ->assertJsonPath('data.summary.admitted', 3)
            ->assertJsonPath('data.summary.people_admitted', 3)
            ->assertJsonPath('data.summary.attendance', 0.75)
            ->assertJsonCount(1, 'data.check_ins')
            ->assertJsonPath('data.check_ins.0.admitted', 3);
    }

    public function test_the_hourly_entries_follow_the_latest_scans(): void
    {
        $order = $this->buy($this->free, 3, null, null);
        Sanctum::actingAs($this->owner);
        $scan = fn (string $code) => $this->postJson("/api/v1/events/{$this->event['id']}/check-in/scans", ['scans' => [['id' => (string) Str::uuid7(), 'code' => $code]]], $this->headers())->assertOk();
        $scan($order['tickets'][0]['code']); // essai du scanner, quelques jours avant
        $this->travel(5)->days();
        $scan($order['tickets'][1]['code']);
        $scan($order['tickets'][2]['code']);

        $hours = $this->stats()->assertJsonPath('data.summary.admitted', 3)->json('data.check_ins');

        $this->assertCount(72, $hours);
        $this->assertSame(2, end($hours)['admitted']);
    }

    public function test_an_order_paid_twice_counts_once_in_payment_methods(): void
    {
        $order = $this->buy($this->paid, 1, ['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0841234567'], null);
        app(TenantContext::class)->run($this->organization->id, fn () => Payment::create([
            'order_id' => $order['id'], 'method' => 'card', 'provider' => 'simulated', 'status' => Payment::SUCCEEDED,
            'amount' => 2000, 'currency' => 'USD', 'completed_at' => now(),
        ]));

        $this->stats()->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.method', 'card')
            ->assertJsonPath('data.payments.0.revenue', 2000);
    }

    public function test_the_accounting_export_lists_paid_orders_without_spreadsheet_formulas(): void
    {
        $this->buy($this->paid, 2, ['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0841234567'], 'whatsapp', '=HYPERLINK("http://x")');
        Sanctum::actingAs($this->owner);

        $response = $this->get("/api/v1/events/{$this->event['id']}/stats/export", [...$this->headers(), 'Accept-Language' => 'fr'])->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

        $this->assertCount(2, $lines);
        $this->assertStringStartsWith("\xEF\xBB\xBF".'"Date du paiement";Référence;', $lines[0]);
        $row = str_getcsv($lines[1], ';', '"', '');
        $this->assertSame('\'=HYPERLINK("http://x")', $row[2]);
        $this->assertSame('2', $row[4]);
        $this->assertSame('40,00', $row[6]);
        $this->assertSame('Orange Money', $row[11]);
        $this->assertSame('WhatsApp', $row[13]);
        $this->assertSame(number_format(((float) str_replace(',', '.', $row[6])) - ((float) str_replace(',', '.', $row[7])), 2, ',', ''), $row[8]);
    }

    public function test_visits_need_a_published_event_and_only_count_one_row_per_day_and_source(): void
    {
        $this->visit('sms');
        $this->visit('sms');
        app(TenantContext::class)->run($this->organization->id, fn () => $this->assertSame(1, EventVisit::count()));

        $this->postJson('/api/v1/public/events/inconnu/visits', ['source' => 'sms'])->assertNotFound();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/events/{$this->event['id']}/unpublish", [], $this->headers())->assertOk();
        $this->leave();
        $this->postJson("/api/v1/public/events/{$this->event['slug']}/visits", ['source' => 'sms'])->assertNotFound();
    }

    public function test_money_roles_need_two_factor_and_other_organizations_get_not_found(): void
    {
        $this->owner->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/events/{$this->event['id']}/stats", $this->headers())->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $this->getJson("/api/v1/events/{$this->event['id']}/stats/export", $this->headers())->assertForbidden();

        $intruder = User::factory()->create();
        $intruder->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();
        $other = $this->createOrganization($intruder, 'Autre');
        Sanctum::actingAs($intruder);
        $this->getJson("/api/v1/events/{$this->event['id']}/stats", ['X-Organization-Id' => $other->id])->assertNotFound();
        $this->getJson("/api/v1/events/{$this->event['id']}/stats/export", ['X-Organization-Id' => $other->id])->assertNotFound();
        $this->getJson("/api/v1/events/{$this->event['id']}/stats", $this->headers())->assertNotFound();
    }

    public function test_a_scanning_agent_cannot_read_the_statistics(): void
    {
        $agent = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($agent) {
            $this->organization->users()->attach($agent->id, ['status' => 'active']);
            $agent->assignRole('check_in_agent');
        });
        Sanctum::actingAs($agent);

        $this->getJson("/api/v1/events/{$this->event['id']}/stats", $this->headers())->assertForbidden();
    }
}
