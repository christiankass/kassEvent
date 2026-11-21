<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentService;
use App\Modules\Payments\Support\StripeSignature;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Jobs\SendTickets;
use App\Modules\Ticketing\Mail\TicketsMail;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private string $eventId;

    private string $slug;

    private string $standard;

    private string $vip;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('Accept-Language', 'fr');
        $this->organization = $this->createOrganization($this->owner = User::factory()->create());
        [$this->eventId, $this->slug, $this->standard, $this->vip] = $this->publishedEvent($this->organization, $this->owner);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function publishedEvent(Organization $organization, User $owner, string $currency = 'USD'): array
    {
        Sanctum::actingAs($owner);
        $headers = ['X-Organization-Id' => $organization->id];
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Concert de Noël', 'starts_at' => now()->addMonth()->format('Y-m-d').'T20:00',
            'venue_name' => 'Salle du Fleuve', 'city' => 'Kinshasa', 'currency' => $currency,
        ], $headers)->assertCreated()->json('data');
        $standard = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Standard', 'price' => $currency === 'CDF' ? '15000' : '25', 'quantity' => 3, 'max_per_order' => 5], $headers)->json('data.id');
        $vip = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'VIP', 'price' => '0', 'quantity' => 10], $headers)->json('data.id');
        $this->postJson("/api/v1/events/{$event['id']}/publish", [], $headers)->assertOk();
        app(TenantContext::class)->clear();
        auth()->forgetGuards();

        return [$event['id'], $event['slug'], $standard, $vip];
    }

    private function order(array $overrides = [], ?string $slug = null): TestResponse
    {
        return $this->postJson('/api/v1/public/events/'.($slug ?? $this->slug).'/orders', [
            'items' => [['ticket_type_id' => $this->standard, 'quantity' => 2]],
            'name' => 'Grace Mbuyi',
            'email' => 'Grace@Example.com',
            'payment' => ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '081 234 5678'],
            ...$overrides,
        ]);
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->organization->id, $callback);
    }

    private function show(string $id, string $key): TestResponse
    {
        return $this->getJson("/api/v1/public/orders/{$id}?key={$key}");
    }

    public function test_a_buyer_orders_without_an_account_and_pays_by_mobile_money(): void
    {
        $response = $this->order()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', 5000)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.buyer_email', 'grace@example.com')
            ->assertJsonPath('data.payment.method', 'mobile_money')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.phone', '+243 81 ••• •• 78')
            ->assertJsonPath('data.tickets', []);
        [$id, $key] = [$response->json('data.id'), $response->json('access_key')];

        // Les places sont réservées pendant le paiement.
        $this->getJson("/api/v1/public/events/{$this->slug}")->assertJsonPath('data.ticket_types.0.available', 1);

        // Mode simulé : validé sur le téléphone quelques secondes plus tard.
        $this->travel(10)->seconds();
        $this->show($id, $key)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.status', 'succeeded')
            ->assertJsonCount(2, 'data.tickets')
            ->assertJsonPath('data.tickets.0.name', 'Standard')
            ->assertJsonPath('data.tickets.0.holder_name', 'Grace Mbuyi');

        $this->assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $this->show($id, $key)->json('data.tickets.0.code'));
        Mail::assertSent(TicketsMail::class, fn (TicketsMail $mail) => $mail->hasTo('grace@example.com') && $mail->order->tickets->count() === 2);
    }

    public function test_the_tickets_email_renders_with_qr_codes(): void
    {
        $response = $this->order(['items' => [['ticket_type_id' => $this->vip, 'quantity' => 1]], 'payment' => null])->assertCreated();
        $order = app(TenantContext::class)->run($this->organization->id, fn () => Order::with(['event.organization', 'tickets.item'])->find($response->json('data.id')));

        $html = (new TicketsMail($order))->locale('fr')->render();

        $this->assertStringContainsString('Concert de Noël', $html);
        $this->assertStringContainsString('VIP', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_free_tickets_are_issued_at_once_without_payment(): void
    {
        $this->order(['items' => [['ticket_type_id' => $this->vip, 'quantity' => 2]], 'payment' => null])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.payment', null)
            ->assertJsonCount(2, 'data.tickets');

        Mail::assertSent(TicketsMail::class);
    }

    public function test_a_paid_order_needs_a_payment_method(): void
    {
        $this->order(['payment' => null])->assertUnprocessable()->assertJsonValidationErrors('payment.method');
        $this->order(['payment' => ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '12345']])
            ->assertUnprocessable()->assertJsonValidationErrors('payment.phone');
        $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_seats_cannot_be_oversold_and_expired_reservations_free_them(): void
    {
        $this->order()->assertCreated();

        $this->assertSame(['Il ne reste que 1 billet(s) « Standard ».'], $this->order()->assertUnprocessable()->json('errors')["items.{$this->standard}"]);

        $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 1]]])->assertCreated();
        $this->assertSame(['Le billet « Standard » est épuisé.'], $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 1]]])->assertUnprocessable()->json('errors')["items.{$this->standard}"]);
        $this->getJson("/api/v1/public/events/{$this->slug}")->assertJsonPath('data.ticket_types.0.available', 0);

        // Paiements jamais validés : les réservations expirent et les places reviennent.
        $this->travel(16)->minutes();
        $this->getJson("/api/v1/public/events/{$this->slug}")->assertJsonPath('data.ticket_types.0.available', 3);
    }

    public function test_max_per_order_and_tickets_of_another_event_are_refused(): void
    {
        $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 6]]])->assertUnprocessable();

        $other = $this->createOrganization($otherOwner = User::factory()->create(), 'Autre Org');
        [, $otherSlug] = $this->publishedEvent($other, $otherOwner);
        $this->order([], $otherSlug)->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_a_failed_mobile_money_payment_can_be_retried_by_card(): void
    {
        $response = $this->order(['payment' => ['method' => 'mobile_money', 'operator' => 'airtel', 'phone' => '0991230000']])->assertCreated();
        [$id, $key] = [$response->json('data.id'), $response->json('access_key')];

        $this->show($id, $key)->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.payment.failure_code', 'INSUFFICIENT_BALANCE')
            ->assertJsonPath('data.status', 'pending');

        $card = $this->postJson("/api/v1/public/orders/{$id}/payments", ['key' => $key, 'payment' => ['method' => 'card']])
            ->assertOk()
            ->assertJsonPath('data.payment.method', 'card')
            ->assertJsonPath('data.payment.simulated', true);

        // Page de carte simulée (lien signé) : « Payer » valide la commande et ramène sur la page de commande.
        $redirect = $card->json('data.payment.redirect_url');
        $this->get($redirect)->assertOk()->assertSee('Paiement par carte');
        $action = $this->get($redirect)->viewData('action');
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post($action, ['outcome' => 'pay'])
            ->assertRedirectContains("/commande/{$id}?cle={$key}");

        $this->show($id, $key)->assertJsonPath('data.status', 'paid')->assertJsonCount(2, 'data.tickets');
    }

    public function test_a_lost_pawapay_request_is_failed_once_pawapay_does_not_know_it(): void
    {
        config(['payments.pawapay.mode' => 'sandbox', 'payments.pawapay.api_token' => 'test-token']);
        Http::fake([
            'api.sandbox.pawapay.io/v2/deposits' => Http::response(null, 504),
            'api.sandbox.pawapay.io/v2/deposits/*' => Http::response(['status' => 'NOT_FOUND']),
        ]);
        $response = $this->order()->assertJsonPath('data.payment.status', 'pending');

        $this->travel(3)->minutes();
        $this->show($response->json('data.id'), $response->json('access_key'))->assertJsonPath('data.payment.failure_code', 'not_found');
    }

    public function test_leaving_the_card_page_cancels_it_before_another_attempt(): void
    {
        $response = $this->order(['payment' => ['method' => 'card']])->assertJsonPath('data.payment.status', 'pending');
        [$id, $key] = [$response->json('data.id'), $response->json('access_key')];

        $this->postJson("/api/v1/public/orders/{$id}/payments", ['key' => $key, 'payment' => ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678']])
            ->assertOk()->assertJsonPath('data.payment.method', 'mobile_money');

        $statuses = app(TenantContext::class)->run($this->organization->id, fn () => Payment::orderBy('id')->pluck('failure_code', 'status')->all());
        $this->assertSame(['failed' => 'cancelled', 'pending' => null], $statuses);
    }

    public function test_repeated_attempts_cannot_hold_seats_forever(): void
    {
        $response = $this->order(['payment' => ['method' => 'card']]);
        [$id, $key] = [$response->json('data.id'), $response->json('access_key')];

        $this->travel(25)->minutes();
        $this->postJson("/api/v1/public/orders/{$id}/payments", ['key' => $key, 'payment' => ['method' => 'card']])->assertOk();

        // Le nouvel essai aurait prolongé la réservation de 32 minutes : elle s'arrête à 45 minutes.
        $this->travel(21)->minutes();
        $this->show($id, $key)->assertJsonPath('data.status', 'expired');
    }

    public function test_a_payment_after_expiry_with_seats_resold_is_flagged_for_refund(): void
    {
        $late = $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 3]], 'payment' => ['method' => 'card']]);
        $this->travel(46)->minutes();
        $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 3]], 'payment' => ['method' => 'card']])->assertCreated();

        $paymentId = app(TenantContext::class)->run($this->organization->id, fn () => Payment::where('order_id', $late->json('data.id'))->value('id'));
        app(TenantContext::class)->run($this->organization->id, fn () => app(PaymentService::class)
            ->settle(Payment::find($paymentId), GatewayStatus::succeeded(7500, 'USD')));

        $this->show($late->json('data.id'), $late->json('access_key'))
            ->assertJsonPath('data.status', 'refund_due')
            ->assertJsonPath('data.tickets', []);
    }

    public function test_malformed_or_uppercase_items_never_crash(): void
    {
        $this->order(['items' => [['quantity' => 1]]])->assertUnprocessable();
        $this->order(['items' => [['ticket_type_id' => [1], 'quantity' => 1]]])->assertUnprocessable();
        $this->order(['items' => [['ticket_type_id' => strtoupper($this->standard), 'quantity' => 1]]])->assertCreated();
    }

    public function test_a_second_mobile_money_attempt_waits_for_the_first(): void
    {
        $response = $this->order()->assertCreated();

        $this->postJson("/api/v1/public/orders/{$response->json('data.id')}/payments", [
            'key' => $response->json('access_key'),
            'payment' => ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678'],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    public function test_the_same_checkout_sent_twice_creates_one_order_and_one_payment(): void
    {
        $key = '9b2f6c1e-4d3a-4f8b-9c7d-2e1a0b3c4d5e';
        $first = $this->order(['idempotency_key' => $key])->assertCreated();
        $second = $this->order(['idempotency_key' => strtoupper($key)])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('access_key'), $second->json('access_key'));
        $this->assertSame([1, 1], $this->inTenant(fn () => [Order::count(), Payment::count()]));

        // Une autre clé (panier modifié) est une nouvelle commande ; une clé mal formée est refusée.
        $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 1]], 'idempotency_key' => '5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a'])->assertCreated();
        $this->order(['idempotency_key' => 'pas-un-uuid'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_a_free_checkout_sent_twice_issues_its_tickets_once(): void
    {
        $body = ['items' => [['ticket_type_id' => $this->vip, 'quantity' => 2]], 'payment' => null, 'idempotency_key' => '0f1e2d3c-4b5a-4987-a6b5-c4d3e2f1a0b9'];
        $this->order($body)->assertCreated();
        $this->order($body)->assertOk()->assertJsonCount(2, 'data.tickets');

        Mail::assertSentCount(1);
    }

    public function test_an_order_has_at_most_one_pending_payment(): void
    {
        $id = $this->order()->assertCreated()->json('data.id');

        // Deux essais arrivés ensemble : le second trouve déjà un paiement en attente.
        $second = $this->inTenant(fn () => app(PaymentService::class)->start(Order::findOrFail($id), ['method' => 'card']));

        $this->assertNull($second);
        $this->assertSame(1, $this->inTenant(fn () => Payment::where('order_id', $id)->count()));
    }

    public function test_order_references_are_long_enough_to_never_repeat(): void
    {
        $this->assertMatchesRegularExpression('/^KE[0-9A-Z]{10}$/', $this->order()->json('data.reference'));
    }

    public function test_an_order_is_only_visible_with_its_key(): void
    {
        $id = $this->order()->json('data.id');

        $this->show($id, 'mauvaise-cle')->assertNotFound();
        $this->getJson("/api/v1/public/orders/{$id}")->assertNotFound();
        $this->show('00000000-0000-0000-0000-000000000000', 'x')->assertNotFound();
        $this->postJson("/api/v1/public/orders/{$id}/payments", ['key' => 'x', 'payment' => ['method' => 'card']])->assertNotFound();
    }

    public function test_an_expired_or_paid_order_cannot_be_paid_again(): void
    {
        $response = $this->order(['payment' => ['method' => 'card']]);
        [$id, $key] = [$response->json('data.id'), $response->json('access_key')];

        $this->travel(40)->minutes();
        $this->show($id, $key)->assertJsonPath('data.status', 'expired');
        $this->postJson("/api/v1/public/orders/{$id}/payments", ['key' => $key, 'payment' => ['method' => 'card']])
            ->assertUnprocessable()->assertJsonPath('errors.payment.0', 'La réservation a expiré. Recommencez votre commande.');
    }

    public function test_pawapay_deposit_is_started_and_confirmed_by_checking_its_status(): void
    {
        config(['payments.pawapay.mode' => 'sandbox', 'payments.pawapay.api_token' => 'test-token']);
        Http::fake([
            'api.sandbox.pawapay.io/v2/deposits' => Http::response(['status' => 'ACCEPTED']),
            'api.sandbox.pawapay.io/v2/deposits/*' => Http::response(['status' => 'FOUND', 'data' => [
                'status' => 'COMPLETED', 'amount' => '50', 'currency' => 'USD',
            ]]),
        ]);

        $response = $this->order()->assertCreated()->assertJsonPath('data.payment.status', 'pending');
        $paymentId = app(TenantContext::class)->run($this->organization->id, fn () => Payment::sole()->id);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.sandbox.pawapay.io/v2/deposits'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['depositId'] === $paymentId
            && $request['amount'] === '50'
            && $request['payer']['accountDetails'] === ['phoneNumber' => '243812345678', 'provider' => 'VODACOM_MPESA_COD']);

        // La notification ne fait que déclencher la vérification auprès de pawaPay.
        $this->postJson('/api/v1/webhooks/pawapay', ['depositId' => $paymentId, 'status' => 'COMPLETED'])->assertOk();

        $this->show($response->json('data.id'), $response->json('access_key'))->assertJsonPath('data.status', 'paid');
    }

    public function test_a_forged_pawapay_notification_validates_nothing(): void
    {
        config(['payments.pawapay.mode' => 'sandbox', 'payments.pawapay.api_token' => 'test-token']);
        Http::fake([
            'api.sandbox.pawapay.io/v2/deposits' => Http::response(['status' => 'ACCEPTED']),
            'api.sandbox.pawapay.io/v2/deposits/*' => Http::response(['status' => 'FOUND', 'data' => ['status' => 'PROCESSING']]),
        ]);
        $response = $this->order();
        $paymentId = app(TenantContext::class)->run($this->organization->id, fn () => Payment::sole()->id);

        $this->postJson('/api/v1/webhooks/pawapay', ['depositId' => $paymentId, 'status' => 'COMPLETED'])->assertOk();

        $this->show($response->json('data.id'), $response->json('access_key'))->assertJsonPath('data.status', 'pending');
    }

    public function test_an_amount_different_from_the_order_is_refused(): void
    {
        config(['payments.pawapay.mode' => 'sandbox', 'payments.pawapay.api_token' => 'test-token']);
        Http::fake([
            'api.sandbox.pawapay.io/v2/deposits' => Http::response(['status' => 'ACCEPTED']),
            'api.sandbox.pawapay.io/v2/deposits/*' => Http::response(['status' => 'FOUND', 'data' => ['status' => 'COMPLETED', 'amount' => '1', 'currency' => 'USD']]),
        ]);
        $response = $this->order();

        $this->show($response->json('data.id'), $response->json('access_key'))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment.failure_code', 'amount_mismatch');
    }

    public function test_a_rejected_or_unreachable_provider_leaves_the_order_retryable(): void
    {
        config(['payments.pawapay.mode' => 'sandbox', 'payments.pawapay.api_token' => 'test-token']);
        Http::fake(['api.sandbox.pawapay.io/*' => Http::sequence()
            ->push(['status' => 'REJECTED', 'failureReason' => ['failureCode' => 'INVALID_PHONE_NUMBER']])
            ->push(null, 500)]);
        $this->order()->assertCreated()->assertJsonPath('data.payment.status', 'failed')->assertJsonPath('data.payment.failure_code', 'INVALID_PHONE_NUMBER');

        // Erreur 5xx : la demande a pu arriver chez pawaPay, le paiement reste en attente de vérification.
        $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.payment.status', 'pending');

        // Carte sans prestataire configuré : échec franc, l'acheteur peut réessayer.
        config(['payments.stripe.mode' => 'test', 'payments.stripe.secret_key' => null]);
        $this->travel(20)->minutes(); // réservations précédentes expirées
        $this->order(['items' => [['ticket_type_id' => $this->standard, 'quantity' => 1]], 'payment' => ['method' => 'card']])
            ->assertCreated()->assertJsonPath('data.payment.failure_code', 'provider_unavailable');
    }

    public function test_stripe_checkout_and_signed_webhook(): void
    {
        config(['payments.stripe.mode' => 'test', 'payments.stripe.secret_key' => 'sk_test_x', 'payments.stripe.webhook_secret' => 'whsec_x']);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
            'api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response(['payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 5000, 'currency' => 'usd']),
        ]);

        $response = $this->order(['payment' => ['method' => 'card']])
            ->assertJsonPath('data.payment.redirect_url', 'https://checkout.stripe.com/c/pay/cs_test_1');
        $paymentId = app(TenantContext::class)->run($this->organization->id, fn () => Payment::sole()->id);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['client_reference_id'] === $paymentId
            && $request['line_items[0][price_data][unit_amount]'] === 2500
            && str_contains($request['success_url'], '/commande/'));

        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['client_reference_id' => $paymentId]]]);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=faux'], $payload)
            ->assertStatus(400);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => StripeSignature::sign($payload, 'whsec_x')], $payload)
            ->assertOk();

        $this->assertSame('paid', app(TenantContext::class)->run($this->organization->id, fn () => Order::sole()->status));
    }

    public function test_cdf_amounts_are_sent_without_cents(): void
    {
        [, $slug, $standard] = $this->publishedEvent($this->organization, $this->owner, 'CDF');
        config(['payments.pawapay.mode' => 'sandbox', 'payments.pawapay.api_token' => 'test-token']);
        Http::fake(['api.sandbox.pawapay.io/*' => Http::response(['status' => 'ACCEPTED'])]);

        $this->order(['items' => [['ticket_type_id' => $standard, 'quantity' => 2]]], $slug)->assertJsonPath('data.total', 3000000);

        Http::assertSent(fn (HttpRequest $request) => $request['amount'] === '30000' && $request['currency'] === 'CDF');
    }

    public function test_tickets_are_queued_for_email_after_commit(): void
    {
        Queue::fake();
        $this->order(['items' => [['ticket_type_id' => $this->vip, 'quantity' => 1]], 'payment' => null]);

        Queue::assertPushedOn('notifications', SendTickets::class);
    }

    public function test_sold_tickets_lock_deletion_and_quantity(): void
    {
        $this->order(['items' => [['ticket_type_id' => $this->vip, 'quantity' => 4]], 'payment' => null])->assertCreated();
        Sanctum::actingAs($this->owner);
        $headers = ['X-Organization-Id' => $this->organization->id];

        $this->deleteJson("/api/v1/ticket-types/{$this->vip}", [], $headers)->assertUnprocessable();
        $this->patchJson("/api/v1/ticket-types/{$this->vip}", ['name' => 'VIP', 'price' => '0', 'quantity' => 3], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->patchJson("/api/v1/ticket-types/{$this->vip}", ['name' => 'VIP', 'price' => '0', 'quantity' => 4], $headers)
            ->assertOk()->assertJsonPath('data.available', 0);

        $this->postJson("/api/v1/events/{$this->eventId}/unpublish", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/events/{$this->eventId}", [], $headers)->assertUnprocessable();
    }
}
