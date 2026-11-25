<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Communication\Models\Delivery;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Mail\TicketsMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta-app-secret';

    private User $owner;

    private Organization $organization;

    private string $eventId;

    private string $slug;

    private string $paid;

    private string $free;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['messaging.app_secret' => self::APP_SECRET, 'messaging.verify_token' => 'verify-me']);
        $this->withHeader('Accept-Language', 'fr');
        $this->organization = $this->createOrganization($this->owner = User::factory()->create());

        Sanctum::actingAs($this->owner);
        $headers = ['X-Organization-Id' => $this->organization->id];
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Mariage de Grace et Paul', 'starts_at' => now()->addMonth()->format('Y-m-d').'T15:00',
            'venue_name' => 'Salle du Fleuve', 'city' => 'Kinshasa', 'currency' => 'USD',
        ], $headers)->assertCreated()->json('data');
        $this->paid = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Standard', 'price' => '25', 'quantity' => 10], $headers)->json('data.id');
        $this->free = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Invité', 'price' => '0', 'quantity' => 10], $headers)->json('data.id');
        $this->postJson("/api/v1/events/{$event['id']}/publish", [], $headers)->assertOk();
        [$this->eventId, $this->slug] = [$event['id'], $event['slug']];
        app(TenantContext::class)->clear();
        auth()->forgetGuards();
    }

    private function order(array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/public/events/{$this->slug}/orders", [
            'items' => [['ticket_type_id' => $this->free, 'quantity' => 2]],
            'name' => 'Grace Mbuyi',
            'email' => 'grace@example.com',
            ...$overrides,
        ]);
    }

    private function deliver(TestResponse $order, array $body): TestResponse
    {
        return $this->postJson("/api/v1/public/orders/{$order->json('data.id')}/deliveries", ['key' => $order->json('access_key'), ...$body]);
    }

    private function show(TestResponse $order): TestResponse
    {
        return $this->getJson("/api/v1/public/orders/{$order->json('data.id')}?key={$order->json('access_key')}");
    }

    private function webhook(array $payload, ?string $secret = self::APP_SECRET): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
        ], $body);
    }

    private function messengerOpened(string $url, string $psid = 'psid-123'): TestResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $this->webhook(['object' => 'page', 'entry' => [['messaging' => [
            ['sender' => ['id' => $psid], 'postback' => ['payload' => 'GET_STARTED', 'referral' => ['ref' => $query['ref'], 'source' => 'SHORTLINK']]],
        ]]]]);
    }

    private function delivery(string $channel): ?Delivery
    {
        return app(TenantContext::class)->run($this->organization->id, fn () => Delivery::where('channel', $channel)->first());
    }

    public function test_free_invitations_go_to_whatsapp_at_once_in_simulated_mode(): void
    {
        $order = $this->order(['whatsapp' => '081 234 5678'])->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.channels', ['whatsapp' => true, 'messenger' => true, 'simulated' => true]);

        $this->show($order)
            ->assertJsonPath('data.deliveries.0.channel', 'whatsapp')
            ->assertJsonPath('data.deliveries.0.status', 'delivered')
            ->assertJsonPath('data.deliveries.0.recipient', '+243 81 ••• •• 78');
        $this->assertSame('243812345678', $this->delivery('whatsapp')->recipient);
        $this->assertCount(2, $this->delivery('whatsapp')->messages);

        // L'organisateur voit le statut de l'envoi, jamais le numéro.
        $this->owner->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();
        Sanctum::actingAs($this->owner);
        $sale = $this->getJson("/api/v1/events/{$this->eventId}/orders", ['X-Organization-Id' => $this->organization->id])
            ->assertOk()->json('data.0');
        $this->assertSame([['channel' => 'whatsapp', 'status' => 'delivered']], $sale['deliveries']);
    }

    public function test_a_paid_order_waits_for_its_payment_before_whatsapp(): void
    {
        $order = $this->order([
            'items' => [['ticket_type_id' => $this->paid, 'quantity' => 1]],
            'whatsapp' => '+33 6 12 34 56 78',
            'payment' => ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678'],
        ])->assertCreated();
        $this->assertSame(Delivery::SCHEDULED, $this->delivery('whatsapp')->status);

        $this->travel(10)->seconds();
        $this->show($order)->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.deliveries.0.status', 'delivered')
            ->assertJsonPath('data.deliveries.0.recipient', '+3361 ••• •• 78');
    }

    public function test_invalid_numbers_are_refused_and_numbers_without_whatsapp_fail(): void
    {
        $this->order(['whatsapp' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('whatsapp');
        $this->order(['whatsapp' => '0612345678'])->assertUnprocessable()->assertJsonValidationErrors('whatsapp');

        $order = $this->order(['whatsapp' => '0812340000'])->assertCreated();
        $this->show($order)->assertJsonPath('data.deliveries.0.status', 'failed')
            ->assertJsonPath('data.deliveries.0.failure_code', 'not_on_whatsapp');

        // L'acheteur corrige son numéro depuis sa page de commande.
        $this->deliver($order, ['channel' => 'whatsapp', 'whatsapp' => '0991234567'])->assertOk()
            ->assertJsonPath('data.deliveries.0.status', 'delivered')
            ->assertJsonPath('data.deliveries.0.failure_code', null);
    }

    public function test_messenger_starts_when_the_buyer_opens_the_page_conversation(): void
    {
        $order = $this->order()->assertCreated();
        config(['messaging.messenger.mode' => 'live', 'messaging.messenger.page_token' => 'page-token', 'messaging.messenger.page_username' => 'kassevent']);
        Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'psid-123', 'message_id' => 'm_1'])]);

        $url = $this->deliver($order, ['channel' => 'messenger'])->assertOk()
            ->assertJsonPath('data.deliveries.0.status', 'awaiting_contact')->json('messenger_url');
        $this->assertMatchesRegularExpression('#^https://m\.me/kassevent\?ref=[0-9a-f-]{36}_[A-Za-z0-9]{32}$#', $url);
        Http::assertNothingSent();

        // Lien falsifié ou signature fausse : rien ne part.
        $this->messengerOpened(preg_replace('/_[A-Za-z0-9]{32}$/', '_'.str_repeat('a', 32), $url))->assertOk();
        $this->webhook(['object' => 'page'], secret: 'wrong')->assertStatus(400);
        Http::assertNothingSent();

        $this->messengerOpened($url)->assertOk();
        $this->show($order)->assertJsonPath('data.deliveries.0.status', 'sent')->assertJsonPath('data.deliveries.0.recipient', null);
        // Accueil, deux billets en image, puis le bouton vers la commande : en réponse à la personne.
        Http::assertSentCount(4);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/me/messages')
            && $request->hasHeader('Authorization', 'Bearer page-token')
            && str_contains($request->body(), 'RESPONSE') && str_contains($request->body(), 'psid-123'));

        // Lien à usage unique : réutilisé par quelqu'un d'autre, il n'envoie rien.
        $this->messengerOpened($url, 'psid-other')->assertOk();
        Http::assertSentCount(4);
        $this->assertSame('psid-123', $this->delivery('messenger')->recipient);
    }

    public function test_simulated_messenger_link_sends_and_returns_to_the_order(): void
    {
        $order = $this->order()->assertCreated();
        $url = $this->deliver($order, ['channel' => 'messenger'])->json('messenger_url');

        $this->get($url)->assertRedirectContains('/commande/'.$order->json('data.id'));
        $this->show($order)->assertJsonPath('data.deliveries.0.status', 'delivered');
        $this->get($url)->assertNotFound();
    }

    public function test_live_whatsapp_sends_one_approved_template_per_ticket_and_follows_receipts(): void
    {
        config(['messaging.whatsapp.mode' => 'live', 'messaging.whatsapp.token' => 'wa-token', 'messaging.whatsapp.phone_number_id' => '1555']);
        $sent = 0;
        Http::fake([
            'graph.facebook.com/*/1555/media' => Http::response(['id' => 'media-1']),
            'graph.facebook.com/*/1555/messages' => function () use (&$sent) {
                return Http::response(['messages' => [['id' => 'wamid.'.(++$sent)]]]);
            },
        ]);

        $order = $this->order(['whatsapp' => '0812345678'])->assertCreated();
        $delivery = $this->delivery('whatsapp');
        $this->assertSame(Delivery::SENT, $delivery->status);
        Http::assertSent(function (HttpRequest $request) use ($order, $delivery) {
            if (! str_ends_with($request->url(), '/1555/messages')) {
                return false;
            }
            $template = $request['template'];

            return $request['to'] === '243812345678'
                && $request['biz_opaque_callback_data'] === $delivery->id
                && $template['name'] === 'kassevent_billet'
                && $template['language']['code'] === 'fr'
                && $template['components'][0]['parameters'][0]['image']['id'] === 'media-1'
                && $template['components'][1]['parameters'][2]['text'] === 'Mariage de Grace et Paul'
                && str_starts_with($template['components'][2]['parameters'][0]['text'], $order->json('data.id').'?cle=');
        });
        $this->assertSame(2, $sent);

        $status = fn (string $state, array $extra = []) => $this->webhook(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'statuses' => [['id' => 'wamid.1', 'status' => $state, 'biz_opaque_callback_data' => $delivery->id, ...$extra]],
        ]]]]]])->assertOk();

        $status('read');
        $status('delivered'); // accusé en retard : ne fait pas reculer
        $this->assertSame(Delivery::READ, $this->delivery('whatsapp')->status);
        $status('failed', ['errors' => [['code' => 131050]]]);
        $this->assertSame(Delivery::READ, $this->delivery('whatsapp')->status);
    }

    public function test_a_refusal_from_meta_fails_the_delivery_without_retrying(): void
    {
        config(['messaging.whatsapp.mode' => 'live', 'messaging.whatsapp.token' => 'wa-token', 'messaging.whatsapp.phone_number_id' => '1555']);
        Http::fake([
            'graph.facebook.com/*/1555/media' => Http::response(['id' => 'media-1']),
            'graph.facebook.com/*/1555/messages' => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400),
        ]);

        $order = $this->order(['whatsapp' => '0812345678'])->assertCreated();
        $this->show($order)->assertJsonPath('data.deliveries.0.status', 'failed')
            ->assertJsonPath('data.deliveries.0.failure_code', 'meta_131026');
    }

    public function test_the_buyer_can_resend_by_email_only_once_paid_and_with_the_key(): void
    {
        $order = $this->order()->assertCreated();
        $this->deliver($order, ['channel' => 'email'])->assertOk();
        Mail::assertSent(TicketsMail::class, 2);

        $this->postJson("/api/v1/public/orders/{$order->json('data.id')}/deliveries", ['key' => 'wrong', 'channel' => 'email'])->assertNotFound();

        $pending = $this->order([
            'items' => [['ticket_type_id' => $this->paid, 'quantity' => 1]],
            'payment' => ['method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678'],
        ]);
        $this->deliver($pending, ['channel' => 'whatsapp', 'whatsapp' => '0812345678'])->assertUnprocessable()->assertJsonValidationErrors('channel');
    }

    public function test_meta_webhook_address_is_verified_with_the_secret_token(): void
    {
        $this->get('/api/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/api/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=12345')->assertForbidden();
    }

    public function test_simulated_channels_are_never_offered_in_production(): void
    {
        $order = $this->order()->assertCreated();
        $this->app['env'] = 'production';

        $this->show($order)->assertJsonPath('data.channels.whatsapp', false)->assertJsonPath('data.channels.messenger', false);
        $this->deliver($order, ['channel' => 'whatsapp', 'whatsapp' => '0812345678'])->assertJsonValidationErrors('channel');
    }

    public function test_a_delivery_of_another_organization_is_out_of_reach(): void
    {
        $order = $this->order(['whatsapp' => '0812345678'])->assertCreated();
        $other = $this->createOrganization(User::factory()->create(), 'Autre');

        $this->assertNull(app(TenantContext::class)->run($other->id, fn () => Delivery::first()));
        $this->assertNotNull($this->delivery('whatsapp'));
        $this->show($order)->assertJsonCount(1, 'data.deliveries');
    }
}
