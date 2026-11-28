<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Campaigns\Jobs\SendCampaignMessages;
use App\Modules\Campaigns\Mail\CampaignMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignMessage;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignsTest extends TestCase
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
        $this->owner = $this->userWithTwoFactor();
        $this->organization = $this->createOrganization($this->owner);
        Sanctum::actingAs($this->owner);
        $this->event = $this->postJson('/api/v1/events', [
            'title' => 'Concert du Fleuve', 'starts_at' => now()->addWeek()->format('Y-m-d').'T19:00',
            'venue_name' => 'Salle du Fleuve', 'city' => 'Kinshasa',
        ], $this->headers())->json('data');
        $this->free = $this->postJson("/api/v1/events/{$this->event['id']}/ticket-types", ['name' => 'Entrée', 'price' => '0', 'quantity' => 100], $this->headers())->json('data.id');
        $this->postJson("/api/v1/events/{$this->event['id']}/publish", [], $this->headers())->assertOk();

        $this->buy('Grace', 'grace@example.com', true);
        $this->buy('Patrick', 'patrick@example.com', false);
        $this->inTenant(fn () => Contact::create([
            'name' => 'Tante Ruth', 'email' => 'ruth@example.com', 'marketing_consent' => true,
            'invitations_count' => 1, 'unsubscribe_key' => str_repeat('k', 40),
        ]));
        Sanctum::actingAs($this->owner);
    }

    private function userWithTwoFactor(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();

        return $user;
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

    private function buy(string $name, string $email, bool $consent, array $extra = []): void
    {
        $this->leave();
        $this->postJson("/api/v1/public/events/{$this->event['slug']}/orders", [
            'items' => [['ticket_type_id' => $this->free, 'quantity' => 1]],
            'name' => $name, 'email' => $email, 'marketing_consent' => $consent, ...$extra,
        ])->assertCreated();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->organization->id, $callback);
    }

    private function draft(array $data = []): array
    {
        return $this->postJson('/api/v1/campaigns', [
            'subject' => 'Le concert approche', 'body' => "Bonjour {nom},\n\nIl reste des places. <b>Vite</b> !",
            'event_id' => $this->event['id'], 'audience' => 'all', ...$data,
        ], $this->headers())->assertCreated()->json('data');
    }

    public function test_a_campaign_goes_only_to_contacts_who_agreed_and_is_tracked(): void
    {
        $this->getJson('/api/v1/campaigns', $this->headers())->assertOk()
            ->assertJsonPath('audiences', ['all' => 2, 'buyers' => 1, 'guests' => 1])->assertJsonPath('demo', true);

        $campaign = $this->draft();
        $html = $this->getJson("/api/v1/campaigns/{$campaign['id']}/preview", $this->headers())->assertOk()->json('data.html');
        $this->assertStringContainsString('&lt;b&gt;Vite&lt;/b&gt;', $html);
        $this->assertStringContainsString('Concert du Fleuve', $html);
        $this->assertStringContainsString('Bonjour Marie', $html);

        $this->postJson("/api/v1/campaigns/{$campaign['id']}/send", [], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.recipients_count', 2)->assertJsonPath('data.sent_count', 2);

        Mail::assertSent(CampaignMail::class, 2);
        Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('patrick@example.com'));
        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
            if (! $mail->hasTo('grace@example.com')) {
                return false;
            }
            $html = $mail->render();
            $this->assertStringContainsString('Bonjour Grace', $html);
            $this->assertStringContainsString('/desinscription/', $html);
            $this->assertStringContainsString('One-Click', (string) $mail->headers()->text['List-Unsubscribe-Post']);

            return true;
        });

        // Une campagne partie ne se modifie plus.
        $this->patchJson("/api/v1/campaigns/{$campaign['id']}", ['subject' => 'x', 'body' => 'y', 'audience' => 'all'], $this->headers())->assertUnprocessable();

        $message = $this->inTenant(fn () => CampaignMessage::whereHas('contact', fn ($q) => $q->where('email', 'grace@example.com'))->first());
        $this->leave();
        $open = "/api/v1/public/campaigns/{$campaign['id']}/messages/{$message->id}/open";
        $this->get($open)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get($open)->assertOk();
        $this->get("/api/v1/public/campaigns/{$campaign['id']}/messages/{$message->id}/click")
            ->assertRedirect(config('app.frontend_url')."/e/{$this->event['slug']}?src=email&cmp={$campaign['id']}");
        $this->get("/api/v1/public/campaigns/{$campaign['id']}/messages/".fake()->uuid().'/click')->assertRedirect(config('app.frontend_url'));

        // Achat arrivé par le bouton : compté pour la campagne.
        $this->buy('Grace', 'grace@example.com', false, ['referral' => 'email', 'campaign_id' => $campaign['id']]);
        $this->buy('Marie', 'marie@example.com', false, ['referral' => 'whatsapp', 'campaign_id' => $campaign['id']]);
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/campaigns/{$campaign['id']}", $this->headers())->assertOk()
            ->assertJsonPath('data.opened_count', 1)->assertJsonPath('data.clicked_count', 1)
            ->assertJsonPath('data.sales.0.orders', 1);
    }

    public function test_audiences_and_a_contact_who_unsubscribes_before_the_send_is_skipped(): void
    {
        $guests = $this->draft(['audience' => 'guests', 'event_id' => null]);
        Queue::fake();
        $this->postJson("/api/v1/campaigns/{$guests['id']}/send", [], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'sending')->assertJsonPath('data.recipients_count', 1);
        $job = null;
        Queue::assertPushed(SendCampaignMessages::class, function ($pushed) use (&$job) {
            $job = $pushed;

            return true;
        });

        $this->inTenant(fn () => Contact::where('email', 'ruth@example.com')->update(['unsubscribed_at' => now()]));
        $this->leave();
        app()->call([$job, 'handle']);

        Mail::assertNotSent(CampaignMail::class);
        $this->inTenant(fn () => $this->assertSame(Campaign::SENT, Campaign::find($guests['id'])->status));
        $this->inTenant(fn () => $this->assertSame(CampaignMessage::SKIPPED, CampaignMessage::first()->status));
    }

    public function test_a_campaign_can_be_scheduled_cancelled_and_sent_by_the_scheduler(): void
    {
        $campaign = $this->draft();
        $this->postJson("/api/v1/campaigns/{$campaign['id']}/send", ['scheduled_at' => now()->subMinute()->toIso8601String()], $this->headers())->assertUnprocessable();
        $at = now()->addHour()->startOfMinute();
        $this->postJson("/api/v1/campaigns/{$campaign['id']}/send", ['scheduled_at' => $at->toIso8601String()], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
        $this->postJson("/api/v1/campaigns/{$campaign['id']}/cancel", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/v1/campaigns/{$campaign['id']}/send", ['scheduled_at' => $at->toIso8601String()], $this->headers())->assertOk();
        $this->leave();

        $this->artisan('campaigns:dispatch')->assertSuccessful();
        Mail::assertNotSent(CampaignMail::class);

        $this->travelTo($at->copy()->addMinute());
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        Mail::assertSent(CampaignMail::class, 2);
        $this->inTenant(fn () => $this->assertSame(Campaign::SENT, Campaign::find($campaign['id'])->status));
    }

    public function test_campaigns_are_isolated_and_need_the_permission(): void
    {
        $campaign = $this->draft();

        $intruder = $this->userWithTwoFactor();
        $other = $this->createOrganization($intruder, 'Autre');
        Sanctum::actingAs($intruder);
        $this->getJson('/api/v1/campaigns', $this->headers($other))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('audiences.all', 0);
        $this->getJson("/api/v1/campaigns/{$campaign['id']}", $this->headers($other))->assertNotFound();
        $this->postJson("/api/v1/campaigns/{$campaign['id']}/send", [], $this->headers($other))->assertNotFound();
        $this->postJson('/api/v1/campaigns', ['subject' => 'x', 'body' => 'y', 'audience' => 'all', 'event_id' => $this->event['id']], $this->headers($other))->assertUnprocessable();

        $agent = User::factory()->create();
        $this->inTenant(function () use ($agent) {
            $this->organization->users()->attach($agent->id, ['status' => 'active']);
            $agent->assignRole('check_in_agent');
        });
        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/campaigns', $this->headers())->assertForbidden();
    }

    public function test_a_contact_unsubscribes_in_one_click_from_the_mail_app(): void
    {
        $contact = $this->inTenant(fn () => Contact::where('email', 'grace@example.com')->first());
        $this->leave();
        $url = parse_url($contact->apiUnsubscribeUrl());
        $this->post($url['path'].'?'.$url['query'], ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->inTenant(fn () => $this->assertNotNull(Contact::find($contact->id)->unsubscribed_at));
    }
}
