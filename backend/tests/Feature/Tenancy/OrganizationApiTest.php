<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Access\Roles;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_organization_makes_the_creator_its_owner(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $id = $this->postJson('/api/v1/organizations', ['name' => 'Mariage de Grâce', 'type' => 'individual', 'currency' => 'CDF'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'individual')
            ->assertJsonPath('data.currency', 'CDF')
            ->json('data.id');

        $this->getJson('/api/v1/organization', ['X-Organization-Id' => $id])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('meta.roles', [Roles::OWNER])
            ->assertJsonCount(count(Roles::PERMISSIONS), 'meta.permissions');
    }

    public function test_creation_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/organizations', ['name' => 'X', 'type' => 'secte', 'currency' => 'EUR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type', 'currency']);
    }

    public function test_long_or_non_latin_names_get_a_valid_slug(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/organizations', ['name' => str_repeat('Événement ', 16), 'type' => 'company'])->assertCreated();
        $this->postJson('/api/v1/organizations', ['name' => '婚礼 庆典', 'type' => 'individual'])
            ->assertCreated()
            ->assertJsonPath('data.slug', fn (string $slug) => str_starts_with($slug, 'organisation-'));
    }

    public function test_i_only_list_organizations_i_belong_to(): void
    {
        $me = User::factory()->create();
        $mine = $this->createOrganization($me, 'Ma structure');
        $this->createOrganization(User::factory()->create(), 'Autre structure');

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_another_organization_returns_404_not_403(): void
    {
        $this->createOrganization($me = User::factory()->create());
        $other = $this->createOrganization(User::factory()->create(), 'Concurrent');

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/organization', ['X-Organization-Id' => $other->id])->assertNotFound();
        $this->getJson('/api/v1/organization', ['X-Organization-Id' => (string) Str::uuid7()])->assertNotFound();
        $this->getJson('/api/v1/organization', ['X-Organization-Id' => 'pas-un-uuid'])->assertNotFound();
    }

    public function test_a_suspended_member_loses_access(): void
    {
        $organization = $this->createOrganization($me = User::factory()->create());
        // Sans organisation courante, la RLS bloque l'écriture : il faut agir depuis l'organisation.
        app(TenantContext::class)->run($organization->id, fn () => Membership::query()->update(['status' => 'suspended']));

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/organization', ['X-Organization-Id' => $organization->id])->assertNotFound();
        $this->getJson('/api/v1/organizations')->assertJsonCount(0, 'data');
    }

    public function test_a_deleted_organization_is_no_longer_reachable(): void
    {
        $organization = $this->createOrganization($me = User::factory()->create());
        $organization->delete();

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/organization', ['X-Organization-Id' => $organization->id])->assertNotFound();
    }

    public function test_the_organization_header_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/organization')->assertStatus(400);
    }

    public function test_roles_are_scoped_to_each_organization(): void
    {
        $me = User::factory()->create();
        $mine = $this->createOrganization($me);
        $theirs = $this->createOrganization($boss = User::factory()->create(), 'Agence partenaire');

        // Dans l'organisation partenaire, je ne suis qu'agent de contrôle.
        app(TenantContext::class)->run($theirs->id, function () use ($theirs, $me) {
            $theirs->users()->attach($me->id, ['status' => Membership::ACTIVE]);
            $me->unsetRelation('roles')->assignRole('check_in_agent');
        });

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/organization', ['X-Organization-Id' => $theirs->id])
            ->assertJsonPath('meta.roles', ['check_in_agent'])
            ->assertJsonPath('meta.permissions', ['checkin.scan', 'events.view']);

        $me->unsetRelation('roles')->unsetRelation('permissions');

        $this->getJson('/api/v1/organization', ['X-Organization-Id' => $mine->id])
            ->assertJsonPath('meta.roles', [Roles::OWNER]);
    }
}
