<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes (module Ticketing) : numéro WhatsApp de l'acheteur et son accord pour recevoir les
 * nouvelles de l'organisateur (case jamais cochée par défaut), transmis au carnet de contacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('buyer_phone', 20)->nullable()->after('buyer_email');
            $table->boolean('marketing_consent')->default(false)->after('buyer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['buyer_phone', 'marketing_consent']);
        });
    }
};
