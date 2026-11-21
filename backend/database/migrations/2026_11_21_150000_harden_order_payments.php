<?php

use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Protections contre le double paiement :
 * - clé d'idempotence de la page d'achat : un même envoi (double clic, réseau qui renvoie)
 *   ne crée qu'une commande ;
 * - au plus un paiement en attente par commande, garanti par la base même si deux demandes
 *   arrivent en même temps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('reference');
            $table->unique(['event_id', 'idempotency_key']);
        });

        // Doublons possibles avant cette protection : seul le plus récent reste en attente.
        // RLS : la mise à jour se fait organisation par organisation.
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            app(TenantContext::class)->run($organizationId, fn () => DB::update(<<<'SQL'
                UPDATE payments SET status = 'failed', failure_code = 'duplicate', completed_at = now()
                WHERE status = 'pending' AND EXISTS (
                    SELECT 1 FROM payments newer
                    WHERE newer.order_id = payments.order_id AND newer.status = 'pending'
                      AND (newer.created_at, newer.id) > (payments.created_at, payments.id)
                )
                SQL));
        }

        DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_order ON payments (order_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_one_pending_per_order');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
