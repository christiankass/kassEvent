<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apparence des billets et invitations d'un événement (une ligne par événement, créée au premier
 * enregistrement). Sans ligne, le billet garde l'apparence kassEvent par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_designs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('template', 20);
            $table->string('kind', 20); // ticket, invitation
            $table->char('primary_color', 7);
            $table->char('accent_color', 7);
            $table->string('font', 20);
            $table->string('headline', 60)->nullable();
            $table->string('message', 280)->nullable();
            $table->string('footer', 200)->nullable();
            $table->string('banner', 10); // none, cover, custom
            $table->string('banner_path')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        RowLevelSecurity::enable('event_designs');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_designs');
    }
};
