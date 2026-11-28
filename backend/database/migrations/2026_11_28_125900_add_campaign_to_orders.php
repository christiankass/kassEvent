<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes (module Ticketing) : campagne e-mail dont le lien a mené à l'achat (?src=email&cmp=…),
 * pour compter les ventes qu'elle a rapportées. Simple identifiant, sans clé étrangère vers le module Campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('campaign_id')->nullable()->after('referral');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('campaign_id');
        });
    }
};
