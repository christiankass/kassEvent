<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet de contacts d'une organisation (module Audience) : acheteurs et invités, une ligne par
 * personne (même e-mail ou même numéro = même contact).
 *
 * - marketing_consent : la personne a coché « Recevoir les nouvelles de l'organisateur » (jamais par
 *   défaut). Seuls ces contacts pourront recevoir des campagnes. unsubscribed_at : désinscrite par son
 *   lien personnel (unsubscribe_key, chiffrée) ; l'accord ne revient que si elle le coche de nouveau.
 * - orders.buyer_phone / orders.marketing_consent : numéro WhatsApp et accord donnés à l'achat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 254)->nullable(); // en minuscules
            $table->string('phone', 20)->nullable(); // international sans « + » (243812345678)
            $table->string('locale', 5)->default('fr');
            $table->boolean('marketing_consent')->default(false);
            $table->string('consent_source', 20)->nullable(); // checkout, rsvp
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->text('unsubscribe_key');
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('invitations_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'last_activity_at']);
        });
        DB::statement('CREATE UNIQUE INDEX contacts_organization_email_unique ON contacts (organization_id, email) WHERE email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX contacts_organization_phone_unique ON contacts (organization_id, phone) WHERE phone IS NOT NULL');
        RowLevelSecurity::enable('contacts');

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
        Schema::dropIfExists('contacts');
    }
};
