<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôle d'entrée.
 *
 * - ticket_scans : journal de chaque scan (valide ou non), envoyé tout de suite ou synchronisé
 *   plus tard par un téléphone resté hors ligne. client_id rend l'envoi idempotent.
 * - ticket_check_ins : une ligne par billet entré, celle du premier scan (le premier scan fait foi,
 *   même s'il arrive après un autre parce que son téléphone était hors ligne).
 * - tickets.companions : nombre d'accompagnants admis avec le billet (invitations d'événements privés).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('companions')->default(0)->after('holder_name');
            $table->index(['event_id', 'id']);
        });

        Schema::create('ticket_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_id'); // identifiant tiré par le téléphone : un même scan n'est compté qu'une fois
            $table->string('code', 64); // contenu lu, tel quel (tronqué)
            $table->string('result', 20); // valid, already_used, invalid, other_event
            $table->string('gate', 40)->nullable();
            $table->boolean('offline')->default(false);
            $table->timestamp('scanned_at'); // heure du scan sur le téléphone, jamais dans le futur
            $table->timestamps();

            $table->unique(['event_id', 'client_id']);
            $table->index(['ticket_id', 'scanned_at']);
        });
        RowLevelSecurity::enable('ticket_scans');

        Schema::create('ticket_check_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('scan_id')->constrained('ticket_scans')->cascadeOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index('event_id');
        });
        RowLevelSecurity::enable('ticket_check_ins');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_check_ins');
        Schema::dropIfExists('ticket_scans');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'id']);
            $table->dropColumn('companions');
        });
    }
};
