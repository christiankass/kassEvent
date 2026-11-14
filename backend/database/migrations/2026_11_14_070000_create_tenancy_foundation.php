<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socle multi-organisation : organisations, appartenances et fonctions SQL
 * qui lisent l'organisation et l'utilisateur courants pour la RLS PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        RowLevelSecurity::createContextFunctions();

        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->string('type', 20); // company, agency, institution, organizer, individual
            $table->char('country', 2)->default('CD');
            $table->char('currency', 3)->default('USD');
            $table->string('locale', 5)->default('fr');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active'); // active, suspended
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        // Lecture : les membres de l'organisation courante, plus mes propres appartenances
        // (pour lister mes organisations). Écriture : seulement dans l'organisation courante.
        RowLevelSecurity::enable('organization_user', readAlso: 'user_id = app_current_user_id()');
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
        RowLevelSecurity::dropContextFunctions();
    }
};
