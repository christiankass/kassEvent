<?php

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
