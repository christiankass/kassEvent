<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envois des billets d'une commande par WhatsApp ou Messenger (l'e-mail part toujours, sans suivi ici).
 * Une ligne par commande et par canal ; un nouvel envoi demandé par l'acheteur la réutilise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // whatsapp, messenger
            // scheduled (attend le paiement), awaiting_contact (Messenger : la personne doit écrire à la page),
            // queued, sent, delivered, read, failed
            $table->string('status', 20);
            $table->text('recipient')->nullable(); // chiffré : numéro WhatsApp ou identifiant Messenger (PSID)
            // Tirage de l'envoi en cours : un envoi relancé par l'acheteur arrête les essais du précédent.
            $table->uuid('attempt')->nullable();
            $table->string('link_secret', 64)->nullable(); // empreinte SHA-256 du secret du lien m.me (Messenger)
            $table->json('messages')->nullable(); // code du billet => identifiant du message chez Meta
            $table->string('failure_code', 60)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'channel']);
        });
        RowLevelSecurity::enable('deliveries');
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
