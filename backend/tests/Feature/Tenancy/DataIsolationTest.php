<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use App\Modules\Tenancy\Database\RowLevelSecurity;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Les deux verrous d'isolation, testés séparément sur une table métier de test :
 * le scope Eloquent (verrou 1) et la RLS PostgreSQL (verrou 2, même en SQL brut).
 */
class DataIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Organization $a;

    private Organization $b;

    private User $ownerOfA;

    protected function setUp(): void
    {
        parent::setUp();

        $role = DB::selectOne('SELECT rolsuper OR rolbypassrls AS bypass FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse($role->bypass, 'Les tests doivent tourner avec un rôle PostgreSQL soumis à la RLS.');

        Schema::create('probes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('label');
        });
        RowLevelSecurity::enable('probes');

        $this->context = app(TenantContext::class);
        $this->a = $this->createOrganization($this->ownerOfA = User::factory()->create(), 'A');
        $this->b = $this->createOrganization(User::factory()->create(), 'B');

        $this->context->run($this->a->id, fn () => Probe::create(['label' => 'billet A']));
        $this->context->run($this->b->id, fn () => Probe::create(['label' => 'billet B']));
    }

    public function test_eloquent_only_sees_the_current_organization(): void
    {
        $this->context->set($this->a->id);

        $this->assertSame(['billet A'], Probe::pluck('label')->all());
        $this->assertNull(Probe::where('label', 'billet B')->first());
    }

    public function test_without_an_organization_nothing_is_visible(): void
    {
        $this->assertSame(0, Probe::count());
        $this->assertSame(0, DB::table('probes')->count());
    }

    public function test_raw_sql_is_still_isolated_by_row_level_security(): void
    {
        $this->context->set($this->a->id);

        $this->assertSame(['billet A'], DB::table('probes')->pluck('label')->all());
        $this->assertSame(0, DB::table('probes')->where('label', 'billet B')->update(['label' => 'piraté']));
        $this->assertSame(0, DB::table('probes')->where('label', 'billet B')->delete());

        $this->context->set($this->b->id);
        $this->assertSame(['billet B'], DB::table('probes')->pluck('label')->all());
    }

    public function test_the_database_refuses_writing_into_another_organization(): void
    {
        $this->context->set($this->a->id);

        $this->expectException(QueryException::class);
        DB::table('probes')->insert(['id' => (string) str()->uuid7(), 'organization_id' => $this->b->id, 'label' => 'intrus']);
    }

    public function test_eloquent_refuses_to_write_without_or_outside_the_context(): void
    {
        try {
            Probe::create(['label' => 'orphelin']);
            $this->fail('Écriture sans organisation acceptée.');
        } catch (MissingTenantContext) {
        }

        $this->context->set($this->a->id);

        try {
            Probe::create(['label' => 'intrus', 'organization_id' => $this->b->id]);
            $this->fail('Écriture vers une autre organisation acceptée.');
        } catch (MissingTenantContext) {
        }

        $this->expectException(MissingTenantContext::class);
        Probe::first()->update(['organization_id' => $this->b->id]);
    }

    public function test_memberships_only_show_my_own_outside_an_organization(): void
    {
        $this->assertSame(0, DB::table('organization_user')->count());

        $this->context->setUser($this->ownerOfA->id);

        $this->assertSame([$this->a->id], DB::table('organization_user')->pluck('organization_id')->all());
    }
}

class Probe extends Model
{
    use BelongsToOrganization, HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}
