<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Ticketing : nombre d'accompagnants admis avec un billet (invitations d'événements privés,
 * affiché à l'agent d'accueil), et index pour lire les billets d'un événement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('companions')->default(0)->after('holder_name');
            $table->index(['event_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'id']);
            $table->dropColumn('companions');
        });
    }
};
