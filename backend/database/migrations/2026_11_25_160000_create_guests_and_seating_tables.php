<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Guests : invités nominatifs d'un événement privé (mariage, anniversaire), leurs réponses,
 * et le plan de salle (capacité, tables, place de chaque invité).
 *
 * - seating_plans : capacité de la salle et places par table choisies par l'organisateur (une ligne par événement).
 * - event_tables : tables numérotées, calculées depuis la capacité puis modifiables une par une.
 * - guests : un invité, ses accompagnants autorisés, sa réponse et sa table. Il répond par un lien
 *   personnel (identifiant + clé secrète) ; quand il confirme, son invitation avec QR code est une
 *   commande gratuite du module Ticketing (order_id), contrôlée au scan comme un billet.
 *
 * Ticketing : une invitation n'a pas de type de billet en vente (ticket_type_id nul), l'invité peut
 * ne pas avoir d'e-mail, et la commande peut être annulée (invité qui se désiste ou retiré de la liste).
 * tickets.seat : table de l'invité, imprimée sur l'invitation et montrée à l'agent d'accueil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seating_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hall_capacity');
            $table->unsignedSmallInteger('seats_per_table');
            $table->timestamps();
        });
        RowLevelSecurity::enable('seating_plans');

        Schema::create('event_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name', 60)->nullable(); // « Table d'honneur », « Famille de la mariée »
            $table->unsignedSmallInteger('seats');
            $table->timestamps();

            $table->unique(['event_id', 'number']);
        });
        RowLevelSecurity::enable('event_tables');

        Schema::create('guests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20)->nullable(); // international sans « + » (243812345678)
            $table->string('email', 254)->nullable();
            $table->string('group_name', 60)->nullable(); // « Famille du marié », « Collègues »
            $table->unsignedSmallInteger('companions_allowed')->default(0);
            $table->string('status', 20)->default('pending'); // pending, confirmed, declined
            $table->unsignedSmallInteger('companions_confirmed')->nullable();
            $table->string('locale', 5)->default('fr');
            $table->text('access_key'); // chiffrée : clé du lien de réponse de l'invité
            $table->foreignUuid('table_id')->nullable()->constrained('event_tables')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('invited_at')->nullable(); // premier envoi
            $table->timestamp('reminded_at')->nullable(); // dernier envoi (relance au plus une fois par jour)
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index('table_id');
        });
        RowLevelSecurity::enable('guests');

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 12)->default('sale')->after('status'); // sale, invitation
            $table->string('buyer_email', 254)->nullable()->change();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->uuid('ticket_type_id')->nullable()->change();
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('seat', 80)->nullable()->after('companions');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('seat'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::dropIfExists('guests');
        Schema::dropIfExists('event_tables');
        Schema::dropIfExists('seating_plans');
    }
};
