<?php

namespace Tests\Feature\Payouts;

use App\Models\User;
use App\Modules\Payouts\Models\LedgerEntry;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Notifications\PayoutAccountChanged;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private string $secret;

    private string $slug;

    private string $paidTicket;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        config(['payouts.commission.percent' => 5, 'payouts.hold_hours' => 24, 'payouts.minimum.USD' => 500]);

        $this->secret = (new Google2FA)->generateSecretKey(32);
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['two_factor_secret' => $this->secret, 'two_factor_confirmed_at' => now()])->save();
        $this->organization = $this->createOrganization($this->owner);

        Sanctum::actingAs($this->owner);
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Gala du Fleuve', 'starts_at' => now()->addMonth()->format('Y-m-d').'T19:00',
            'venue_name' => 'Hôtel du Fleuve', 'city' => 'Kinshasa',
        ], $this->headers())->json('data');
        $this->slug = $event['slug'];
        $this->paidTicket = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Standard', 'price' => '20', 'quantity' => 100], $this->headers())->json('data.id');
        $free = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Invité', 'price' => '0', 'quantity' => 50], $this->headers())->json('data.id');
        $this->postJson("/api/v1/events/{$event['id']}/publish", [], $this->headers())->assertOk();
        $this->leave();

        $this->postJson("/api/v1/public/events/{$this->slug}/orders", [
            'items' => [['ticket_type_id' => $free, 'quantity' => 2]], 'name' => 'Invité', 'email' => 'i@example.com',
        ])->assertCreated();
        $this->buy(2);
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

    /** Achat payé en mobile money simulé (validé quelques secondes plus tard). */
    private function buy(int $quantity): void
    {
        $this->leave();
        $order = $this->postJson("/api/v1/public/events/{$this->slug}/orders", [
            'items' => [['ticket_type_id' => $this->paidTicket, 'quantity' => $quantity]],
            'name' => 'Acheteur', 'email' => 'a@example.com',
            'payment' => ['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0841234567'],
        ])->assertCreated();
        $this->travel(10)->seconds();
        $this->getJson("/api/v1/public/orders/{$order->json('data.id')}?key={$order->json('access_key')}")->assertJsonPath('data.status', 'paid');
        $this->leave();
    }

    private function code(): string
    {
        // Un code ne sert qu'une fois ; les codes suivent l'horloge réelle, pas celle des tests.
        Cache::flush();

        return (new Google2FA)->getCurrentOtp($this->secret);
    }

    private function setAccount(array $account = ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678'])
    {
        Sanctum::actingAs($this->owner);

        return $this->putJson('/api/v1/payouts/account', [...$account, 'code' => $this->code()], $this->headers());
    }

    private function payouts()
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/v1/payouts', $this->headers())->assertOk();
    }

    public function test_a_paid_order_is_credited_minus_commission_and_held_before_payout(): void
    {
        $this->payouts()
            ->assertJsonPath('data.balances.0.currency', 'USD')
            ->assertJsonPath('data.balances.0.sales', 4000)
            ->assertJsonPath('data.balances.0.commission', 200)
            ->assertJsonPath('data.balances.0.available', 0)
            ->assertJsonPath('data.balances.0.on_hold', 3800)
            ->assertJsonCount(2, 'data.entries') // la commande gratuite n'apparaît pas
            ->assertJsonPath('data.entries.0.type', 'commission')
            ->assertJsonPath('data.entries.1.type', 'sale')
            ->assertJsonPath('data.entries.1.label', 'Gala du Fleuve')
            ->assertJsonPath('data.entries.1.tickets', 2)
            ->assertJsonPath('data.account', null);

        $this->travel(25)->hours();
        $this->payouts()->assertJsonPath('data.balances.0.available', 3800)->assertJsonPath('data.balances.0.on_hold', 0);
    }

    public function test_the_payout_account_needs_a_fresh_two_factor_code_and_is_returned_masked(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/payouts/account', ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678', 'code' => '000000'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->setAccount(['method' => 'bank', 'bank_name' => 'Rawbank', 'account_holder' => 'Kin Events SARL', 'account_number' => '0501 2345 6789 0123', 'swift' => 'rawbcdki'])
            ->assertOk()
            ->assertJsonPath('data.method', 'bank')
            ->assertJsonPath('data.account_last4', '0123')
            ->assertJsonPath('data.active', true) // premier compte : utilisable tout de suite
            ->assertJsonMissingPath('data.account_number');

        $stored = app(TenantContext::class)->run($this->organization->id, fn () => \DB::table('payout_accounts')->value('account_number'));
        $this->assertStringNotContainsString('0123', $stored); // chiffré en base

        $this->setAccount()->assertOk()->assertJsonPath('data.phone', '+243 81 ••• •• 78')->assertJsonPath('data.active', false);
        Notification::assertSentTo($this->owner, PayoutAccountChanged::class);
    }

    public function test_the_available_balance_is_paid_out_to_mobile_money(): void
    {
        $this->setAccount()->assertOk();
        $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'below_minimum'); // ventes encore en attente

        $this->travel(25)->hours();
        $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())->assertCreated()
            ->assertJsonPath('data.amount', 3800)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.phone', '+243 81 ••• •• 78');
        $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'in_progress');
        $this->payouts()->assertJsonPath('data.balances.0.available', 0)->assertJsonPath('data.balances.0.in_progress', 3800);

        $this->travel(10)->seconds();
        $this->payouts()
            ->assertJsonPath('data.payouts.0.status', 'succeeded')
            ->assertJsonPath('data.balances.0.paid_out', 3800)
            ->assertJsonPath('data.balances.0.in_progress', 0)
            ->assertJsonPath('data.balances.0.available', 0);
    }

    public function test_a_failed_payout_returns_the_money_to_the_balance(): void
    {
        $this->setAccount(['method' => 'mobile_money', 'operator' => 'airtel', 'phone' => '0990000000'])->assertOk();
        $this->travel(25)->hours();
        $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())->assertCreated();

        $this->travel(10)->seconds();
        $this->payouts()
            ->assertJsonPath('data.payouts.0.status', 'failed')
            ->assertJsonPath('data.balances.0.available', 3800)
            ->assertJsonPath('data.entries.0.type', 'payout_reversal');
    }

    public function test_a_new_payout_account_waits_before_receiving_money(): void
    {
        config(['payouts.account_change_delay_hours' => 24]);
        $this->setAccount()->assertOk();
        $this->travel(25)->hours(); // les ventes sont disponibles
        $this->setAccount(['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0851234567'])->assertOk()->assertJsonPath('data.active', false);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'account_pending');
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame(0, app(TenantContext::class)->run($this->organization->id, fn () => Payout::count()));

        $this->travel(25)->hours();
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame('243851234567', app(TenantContext::class)->run($this->organization->id, fn () => Payout::sole()->phone));
    }

    public function test_automatic_payouts_run_for_each_organization_and_do_not_retry_a_failure(): void
    {
        $this->setAccount(['method' => 'mobile_money', 'operator' => 'airtel', 'phone' => '0990000000'])->assertOk();
        $this->leave();
        $this->travel(25)->hours();

        $this->artisan('payouts:run')->assertSuccessful();
        $count = fn () => app(TenantContext::class)->run($this->organization->id, fn () => [Payout::count(), Payout::value('trigger')]);
        $this->assertSame([1, 'automatic'], $count());

        $this->travel(1)->minutes();
        $this->artisan('payouts:run')->assertSuccessful(); // vérifie le reversement : échoué, montant rendu
        $this->artisan('payouts:run')->assertSuccessful(); // pas de nouvel essai vers le même compte
        $this->assertSame(1, $count()[0]);

        // Compte corrigé : le reversement repart au passage suivant.
        $this->setAccount()->assertOk();
        $this->leave();
        $this->travel(25)->hours();
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame(2, $count()[0]);
    }

    public function test_bank_payouts_are_completed_by_the_kassevent_team(): void
    {
        $this->setAccount(['method' => 'bank', 'bank_name' => 'Equity BCDC', 'account_holder' => 'Kin Events', 'account_number' => 'CD12345678'])->assertOk();
        $this->travel(25)->hours();
        $id = $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())->assertCreated()
            ->assertJsonPath('data.method', 'bank')->assertJsonPath('data.account_last4', '5678')->json('data.id');
        $this->leave();

        $this->artisan('payouts:complete', ['payout' => $id, '--reference' => 'VIR-001'])->assertSuccessful();
        $this->artisan('payouts:complete', ['payout' => $id])->assertFailed(); // déjà enregistré

        $this->payouts()->assertJsonPath('data.payouts.0.status', 'succeeded')->assertJsonPath('data.balances.0.paid_out', 3800);
    }

    public function test_pawapay_payouts_are_confirmed_by_checking_their_status(): void
    {
        config(['payouts.mode' => 'sandbox', 'payments.pawapay.api_token' => 'test-token']);
        Http::fake([
            'api.sandbox.pawapay.io/v2/payouts' => Http::response(['status' => 'ACCEPTED']),
            'api.sandbox.pawapay.io/v2/payouts/*' => Http::sequence()
                ->push(['status' => 'FOUND', 'data' => ['status' => 'PROCESSING']])
                ->push(['status' => 'FOUND', 'data' => ['status' => 'COMPLETED', 'amount' => '38', 'currency' => 'USD']]),
        ]);
        $this->setAccount()->assertOk();
        $this->travel(25)->hours();
        $id = $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())->assertCreated()->json('data.id');
        $this->leave();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.sandbox.pawapay.io/v2/payouts'
            && $request['payoutId'] === $id && $request['amount'] === '38' && $request['currency'] === 'USD'
            && $request['recipient']['accountDetails'] === ['phoneNumber' => '243812345678', 'provider' => 'VODACOM_MPESA_COD']);

        // Une notification ne valide rien seule : pawaPay dit encore « en cours ».
        $this->postJson('/api/v1/webhooks/pawapay/payouts', ['payoutId' => $id, 'status' => 'COMPLETED'])->assertOk();
        $status = fn () => app(TenantContext::class)->run($this->organization->id, fn () => Payout::find($id)->status);
        $this->assertSame('pending', $status());

        $this->postJson('/api/v1/webhooks/pawapay/payouts', ['payoutId' => $id])->assertOk();
        $this->assertSame('succeeded', $status());
    }

    public function test_only_owner_and_finance_choose_where_money_goes(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['two_factor_secret' => $this->secret, 'two_factor_confirmed_at' => now()])->save();
        $manager = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($admin, $manager) {
            $this->organization->users()->attach([$admin->id => ['status' => 'active'], $manager->id => ['status' => 'active']]);
            $admin->assignRole('admin');
            $manager->assignRole('event_manager');
        });
        $this->leave();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/payouts', $this->headers())->assertOk();
        $this->putJson('/api/v1/payouts/account', ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678', 'code' => $this->code()], $this->headers())->assertForbidden();
        $this->postJson('/api/v1/payouts', ['currency' => 'USD'], $this->headers())->assertForbidden();

        $this->leave();
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/payouts', $this->headers())->assertForbidden();
    }

    public function test_money_roles_need_two_factor_authentication(): void
    {
        $this->owner->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        $this->payoutsWithoutCheck()->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    }

    private function payoutsWithoutCheck()
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/v1/payouts', $this->headers());
    }

    public function test_each_organization_only_sees_its_own_money(): void
    {
        $this->setAccount()->assertOk();
        $this->leave();
        $intruder = User::factory()->create();
        $intruder->forceFill(['two_factor_secret' => $this->secret, 'two_factor_confirmed_at' => now()])->save();
        $other = $this->createOrganization($intruder, 'Autre');
        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/payouts', $this->headers($other))->assertOk()
            ->assertJsonPath('data.account', null)
            ->assertJsonCount(0, 'data.entries')
            ->assertJsonPath('data.balances.0.sales', 0);
        $this->getJson('/api/v1/payouts', $this->headers())->assertNotFound();

        // Même sans le scope Eloquent, la base ne montre rien d'une autre organisation.
        $rows = app(TenantContext::class)->run($other->id, fn () => LedgerEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, $rows);
    }
}
