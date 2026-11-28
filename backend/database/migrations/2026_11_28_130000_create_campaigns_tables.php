<?php

use App\Modules\Access\Roles;
use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes e-mail (module Campaigns).
 *
 * - campaigns : un message écrit par l'organisateur, envoyé aux contacts qui ont donné leur accord
 *   (tous, acheteurs ou invités), tout de suite ou à une date choisie. Compteurs d'envoi, d'ouvertures et de clics.
 * - campaign_messages : un envoi par contact, figé au départ de la campagne. Aucune donnée personnelle
 *   copiée : l'adresse est relue sur le contact au moment de l'envoi (un contact supprimé n'est plus écrit).
 * - Permission campaigns.manage (propriétaire, administrateur, responsable d'événements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20)->default('email');
            $table->string('subject', 150);
            $table->text('body');
            $table->string('button_label', 40)->nullable();
            $table->string('audience', 20)->default('all');
            $table->string('status', 20)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'scheduled_at']);
        });
        RowLevelSecurity::enable('campaigns');

        Schema::create('campaign_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued, sent, failed, skipped
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
        });
        RowLevelSecurity::enable('campaign_messages');

        Roles::sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_messages');
        Schema::dropIfExists('campaigns');
    }
};
