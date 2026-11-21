<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesTest extends TestCase
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
        $this->owner->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();
        $this->organization = $this->createOrganization($this->owner);

        Sanctum::actingAs($this->owner);
        $headers = $this->headers();
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Gala', 'starts_at' => now()->addWeek()->format('Y-m-d').'T19:00',
            'venue_name' => 'Hôtel du Fleuve', 'city' => 'Kinshasa',
        ], $headers)->json('data');
        $this->eventId = $event['id'];
        $paid = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Standard', 'price' => '20', 'quantity' => 50], $headers)->json('data.id');
        $free = $this->postJson("/api/v1/events/{$event['id']}/ticket-types", ['name' => 'Invité', 'price' => '0', 'quantity' => 50], $headers)->json('data.id');
        $this->postJson("/api/v1/events/{$event['id']}/publish", [], $headers)->assertOk();
        app(TenantContext::class)->clear();
        auth()->forgetGuards();

        $buy = fn (string $ticket, int $quantity, ?array $payment) => $this->postJson("/api/v1/public/events/{$event['slug']}/orders", [
            'items' => [['ticket_type_id' => $ticket, 'quantity' => $quantity]],
            'name' => 'Acheteur', 'email' => 'a@example.com', 'payment' => $payment,
        ])->assertCreated();
        $buy($free, 3, null);
        $mobile = $buy($paid, 2, ['method' => 'mobile_money', 'operator' => 'orange', 'phone' => '0841234567']);
        $buy($paid, 1, ['method' => 'card']); // jamais payée
        // Mode simulé : le mobile money est validé quelques secondes plus tard, vu depuis la page de commande.
        $this->travel(10)->seconds();
        $this->getJson("/api/v1/public/orders/{$mobile->json('data.id')}?key={$mobile->json('access_key')}")->assertJsonPath('data.status', 'paid');
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    public function test_the_organizer_sees_sales_and_revenue(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/events/{$this->eventId}/orders", $this->headers())->assertOk()
            ->assertJsonPath('summary.currency', 'USD')
            ->assertJsonPath('summary.revenue', 4000)
            ->assertJsonPath('summary.orders', 2)
            ->assertJsonPath('summary.tickets_sold', 5)
            ->assertJsonPath('summary.ticket_types.0.sold', 2)
            ->assertJsonPath('summary.ticket_types.1.sold', 3)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.payment_method', 'card')
            ->assertJsonPath('data.1.payment_method', 'mobile_money');
    }

    public function test_money_roles_need_two_factor_authentication(): void
    {
        $this->owner->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/events/{$this->eventId}/orders", $this->headers())
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');
    }

    public function test_other_roles_see_sales_without_two_factor(): void
    {
        $manager = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($manager) {
            $this->organization->users()->attach($manager->id, ['status' => 'active']);
            $manager->assignRole('event_manager');
        });
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/events/{$this->eventId}/orders", $this->headers())->assertOk();
    }

    public function test_sales_of_another_organization_are_not_found(): void
    {
        $intruder = User::factory()->create();
        $intruder->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();
        $other = $this->createOrganization($intruder, 'Autre');
        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/events/{$this->eventId}/orders", ['X-Organization-Id' => $other->id])->assertNotFound();
        $this->getJson("/api/v1/events/{$this->eventId}/orders", $this->headers())->assertNotFound();
    }
}
