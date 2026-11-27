<?php

namespace Tests\Feature\Contributions;

use App\Models\User;
use App\Modules\Contributions\Models\Contribution;
use App\Modules\Contributions\Support\ContributionFees;
use App\Modules\Payouts\Models\LedgerEntry;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ContributionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private string $secret;

    private string $eventId;

    private string $bookId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        config([
            'payouts.hold_hours' => 24,
            'contributions.fees.mobile_money' => ['percent' => 3, 'fixed' => ['USD' => 0, 'CDF' => 0]],
            'contributions.fees.card' => ['percent' => 3.9, 'fixed' => ['USD' => 30, 'CDF' => 80000]],
        ]);

        $this->secret = (new Google2FA)->generateSecretKey(32);
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['two_factor_secret' => $this->secret, 'two_factor_confirmed_at' => now()])->save();
        $this->organization = $this->createOrganization($this->owner, 'Famille Mbuyi', 'individual');

        $this->asOwner();
        $this->eventId = $this->postJson('/api/v1/events', [
            'title' => 'Obsèques de Papa Jean', 'starts_at' => now()->addWeek()->format('Y-m-d').'T10:00',
            'venue_name' => 'Paroisse Saint-Joseph', 'city' => 'Kinshasa',
        ], $this->headers())->assertCreated()->json('data.id');
        $this->bookId = $this->putJson("/api/v1/events/{$this->eventId}/wishes/settings", ['open' => true, 'tone' => 'tribute'], $this->headers())
            ->assertOk()->json('book.id');
    }

    private function asOwner(?User $user = null): void
    {
        app(TenantContext::class)->clear();
        Sanctum::actingAs($user ?? $this->owner);
    }

    private function asVisitor(): void
    {
        app(TenantContext::class)->clear();
        auth()->forgetGuards();
    }

    /** @return array<string, string> */
    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id];
    }

    private function addPayoutAccount(): void
    {
        $this->asOwner();
        Cache::flush(); // un code de double authentification ne sert qu'une fois
        $this->putJson('/api/v1/payouts/account', [
            'method' => 'mobile_money', 'operator' => 'vodacom', 'phone' => '0812345678', 'code' => (new Google2FA)->getCurrentOtp($this->secret),
        ], $this->headers())->assertOk();
    }

    private function openContributions(array $settings = ['open' => true, 'beneficiary' => 'la famille de Papa Jean'])
    {
        $this->asOwner();

        return $this->putJson("/api/v1/events/{$this->eventId}/contributions/settings", $settings, $this->headers());
    }

    private function contribute(array $data = [])
    {
        $this->asVisitor();
        $amount = $data['amount'] ?? 1000;
        $currency = $data['currency'] ?? 'USD';
        $method = $data['payment']['method'] ?? 'mobile_money';

        return $this->postJson("/api/v1/public/wish-books/{$this->bookId}/contributions", [
            'amount' => $amount,
            'currency' => $currency,
            'total' => $amount + ContributionFees::for($amount, $currency, $method),
            'name' => 'Tonton Pierre',
            'message' => 'Courage à toute la famille.',
            'payment' => ['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0841234567'],
            ...$data,
        ]);
    }

    private function show(string $id, string $key)
    {
        $this->asVisitor();

        return $this->getJson("/api/v1/public/contributions/{$id}?key={$key}");
    }

    public function test_fees_are_added_so_the_beneficiary_receives_the_full_amount(): void
    {
        // 10 USD en mobile money (3 %) : 10,31 USD payés, 3 % de 10,31 = 0,31 retenus par le prestataire.
        $this->assertSame(31, ContributionFees::for(1000, 'USD', 'mobile_money'));
        // Carte (3,9 % + 0,30 USD) : 10,72 USD payés.
        $this->assertSame(72, ContributionFees::for(1000, 'USD', 'card'));
        // Francs congolais : frais arrondis au franc supérieur (10 000 FC : 310 FC de frais).
        $this->assertSame(31000, ContributionFees::for(1000000, 'CDF', 'mobile_money'));

        foreach ([[1000, 'USD', 'card'], [123456, 'USD', 'mobile_money'], [5000000, 'CDF', 'card']] as [$amount, $currency, $method]) {
            $total = $amount + ContributionFees::for($amount, $currency, $method);
            $percent = config("contributions.fees.{$method}.percent");
            $kept = (int) ceil($total * $percent / 100) + config("contributions.fees.{$method}.fixed.{$currency}");
            $this->assertGreaterThanOrEqual($amount, $total - $kept + 1, "{$amount} {$currency} {$method}");
        }
    }

    public function test_contributions_need_a_payout_account_and_show_on_the_public_page(): void
    {
        $this->asVisitor();
        $this->getJson("/api/v1/public/wish-books/{$this->bookId}")->assertOk()->assertJsonPath('data.contributions', null);
        $this->contribute()->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->openContributions()->assertUnprocessable()->assertJsonValidationErrors('open');

        $this->addPayoutAccount();
        $this->openContributions()->assertOk()
            ->assertJsonPath('book.contributions_open', true)
            ->assertJsonPath('book.beneficiary', 'la famille de Papa Jean');

        $this->asVisitor();
        $this->getJson("/api/v1/public/wish-books/{$this->bookId}")->assertOk()
            ->assertJsonPath('data.contributions.beneficiary', 'la famille de Papa Jean')
            ->assertJsonPath('data.contributions.default_currency', 'USD')
            ->assertJsonPath('data.contributions.limits.CDF.min', 100000)
            ->assertJsonPath('data.contributions.fees.card.percent', 3.9)
            ->assertJsonPath('data.contributions.fees.card.fixed.USD', 30);
    }

    public function test_a_mobile_money_contribution_is_credited_in_full_without_commission(): void
    {
        $this->addPayoutAccount();
        $this->openContributions()->assertOk();

        $created = $this->contribute()->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 1000)
            ->assertJsonPath('data.payment.amount', 1031)
            ->assertJsonPath('data.payment.phone', '+243 84 ••• •• 67');
        $this->assertSame('KC', substr($created->json('data.reference'), 0, 2));

        $this->travel(10)->seconds();
        $this->show($created->json('data.id'), $created->json('access_key'))->assertOk()->assertJsonPath('data.status', 'paid');

        $entries = app(TenantContext::class)->run($this->organization->id, fn () => LedgerEntry::all());
        $this->assertCount(1, $entries);
        $this->assertSame([LedgerEntry::CONTRIBUTION, 1000, 'USD'], [$entries[0]->type, $entries[0]->amount, $entries[0]->currency]);

        $this->asOwner();
        $this->getJson("/api/v1/events/{$this->eventId}/contributions", $this->headers())->assertOk()
            ->assertJsonPath('data.0.contributor_name', 'Tonton Pierre')
            ->assertJsonPath('data.0.message', 'Courage à toute la famille.')
            ->assertJsonPath('totals.0.amount', 1000)
            ->assertJsonPath('totals.0.count', 1);
        $this->getJson('/api/v1/payouts', $this->headers())->assertOk()
            ->assertJsonPath('data.balances.0.contributions', 1000)
            ->assertJsonPath('data.balances.0.commission', 0)
            ->assertJsonPath('data.balances.0.on_hold', 1000);
    }

    public function test_the_total_shown_must_match_and_amounts_respect_the_limits(): void
    {
        $this->addPayoutAccount();
        $this->openContributions()->assertOk();

        $this->contribute(['total' => 1000])->assertUnprocessable()->assertJsonValidationErrors('total');
        $this->contribute(['amount' => 50])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->contribute(['amount' => 100050, 'currency' => 'CDF'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->contribute(['amount' => 500000, 'currency' => 'CDF'])->assertCreated()->assertJsonPath('data.currency', 'CDF');
        $this->contribute(['message' => 'Voir www.arnaque.com'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->contribute(['name' => 'J'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_an_anonymous_contribution_can_be_retried_by_card_after_a_failure(): void
    {
        $this->addPayoutAccount();
        $this->openContributions()->assertOk();

        $created = $this->contribute([
            'name' => '   ',
            'payment' => ['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0841230000'],
        ])->assertCreated()->assertJsonPath('data.contributor_name', null);
        [$id, $key] = [$created->json('data.id'), $created->json('access_key')];
        $this->show($id, $key)->assertJsonPath('data.payment.status', 'failed')->assertJsonPath('data.status', 'pending');

        $this->asVisitor();
        $this->postJson("/api/v1/public/contributions/{$id}/payments", ['key' => $key, 'total' => 1031, 'payment' => ['method' => 'card']])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $retry = $this->postJson("/api/v1/public/contributions/{$id}/payments", ['key' => $key, 'total' => 1072, 'payment' => ['method' => 'card']])
            ->assertOk()->assertJsonPath('data.payment.method', 'card')->assertJsonPath('data.payment.amount', 1072);

        // Page de carte simulée : « Payer ».
        $paymentId = app(TenantContext::class)->run($this->organization->id, fn () => Contribution::findOrFail($id)->latestPayment->id);
        $this->assertNotNull($retry->json('data.payment.redirect_url'));
        $this->post(URL::temporarySignedRoute('payments.simulator.complete', now()->addMinutes(30), ['payment' => $paymentId, 'return' => 'http://localhost']), ['outcome' => 'pay'])
            ->assertRedirect();

        $this->show($id, $key)->assertJsonPath('data.status', 'paid');
        $this->asVisitor();
        $this->postJson("/api/v1/public/contributions/{$id}/payments", ['key' => $key, 'total' => 1072, 'payment' => ['method' => 'card']])
            ->assertUnprocessable();
    }

    public function test_a_repeated_submission_creates_one_contribution(): void
    {
        $this->addPayoutAccount();
        $this->openContributions()->assertOk();
        $key = (string) Str::uuid();

        $first = $this->contribute(['idempotency_key' => $key])->assertCreated();
        $this->contribute(['idempotency_key' => $key])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, app(TenantContext::class)->run($this->organization->id, fn () => Contribution::count()));

        // Réponse perdue, puis contributions fermées : le même envoi rend encore le lien personnel.
        $this->openContributions(['open' => false])->assertOk();
        $this->contribute(['idempotency_key' => $key])->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('access_key', $first->json('access_key'));
    }

    public function test_an_event_that_received_contributions_cannot_be_deleted(): void
    {
        $this->addPayoutAccount();
        $this->openContributions()->assertOk();
        $this->contribute()->assertCreated();

        $this->asOwner();
        $this->deleteJson("/api/v1/events/{$this->eventId}", [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->assertSame(1, app(TenantContext::class)->run($this->organization->id, fn () => Contribution::count()));
    }

    public function test_unknown_contributions_wrong_keys_and_closed_books_are_refused(): void
    {
        $this->addPayoutAccount();
        $this->openContributions()->assertOk();
        $created = $this->contribute()->assertCreated();

        $this->show($created->json('data.id'), 'wrong')->assertNotFound();
        $this->show((string) Str::uuid(), 'x')->assertNotFound();
        $this->asVisitor();
        $this->postJson('/api/v1/public/wish-books/'.Str::uuid().'/contributions', [])->assertUnprocessable();

        $this->openContributions(['open' => false])->assertOk()->assertJsonPath('book.contributions_open', false);
        $this->contribute()->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_only_money_roles_with_two_factor_see_and_manage_contributions(): void
    {
        $manager = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($manager) {
            $this->organization->users()->attach([$manager->id => ['status' => 'active']]);
            $manager->assignRole('event_manager');
        });

        $this->asOwner($manager);
        $this->getJson("/api/v1/events/{$this->eventId}/contributions", $this->headers())->assertForbidden();
        $this->putJson("/api/v1/events/{$this->eventId}/contributions/settings", ['open' => true], $this->headers())->assertForbidden();

        $this->owner->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->asOwner();
        $this->getJson("/api/v1/events/{$this->eventId}/contributions", $this->headers())
            ->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    }

    public function test_another_organization_cannot_see_the_contributions(): void
    {
        $this->addPayoutAccount();
        $this->openContributions()->assertOk();
        $this->contribute()->assertCreated();

        $intruder = User::factory()->create();
        $intruder->forceFill(['two_factor_secret' => $this->secret, 'two_factor_confirmed_at' => now()])->save();
        $other = $this->createOrganization($intruder, 'Autre');
        $this->asOwner($intruder);

        $this->getJson("/api/v1/events/{$this->eventId}/contributions", $this->headers($other))->assertNotFound();
        $this->getJson("/api/v1/events/{$this->eventId}/contributions", $this->headers())->assertNotFound();
        $this->assertSame(0, app(TenantContext::class)->run($other->id, fn () => Contribution::withoutGlobalScopes()->count()));
    }
}
