<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiques d'un événement (module Analytics).
 *
 * - event_visits : visites de la page publique, comptées par jour (à l'heure du lieu) et par source
 *   (lien partagé sur WhatsApp, Facebook, affiche avec QR code…). Un simple compteur : aucune donnée
 *   sur la personne qui visite.
 * - orders.referral : source de la visite qui a mené à la commande, pour savoir quel canal fait vendre.
 * - order_items.order_id : index pour additionner les billets des commandes payées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('source', 20);
            $table->unsignedInteger('visits')->default(0);

            $table->unique(['event_id', 'day', 'source']);
        });
        RowLevelSecurity::enable('event_visits');

        Schema::table('orders', function (Blueprint $table) {
            $table->string('referral', 20)->nullable()->after('source');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('referral');
        });
        Schema::dropIfExists('event_visits');
    }
};
