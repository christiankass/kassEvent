<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livre de vœux d'un événement (anniversaire, mariage) : les proches laissent un message par un lien
 * public, sans compte, et l'organisateur les lit et les modère.
 *
 * - wish_books : un par événement. Son identifiant (aléatoire) forme le lien public et est inscrit dans
 *   tenant_keys : l'événement n'a pas besoin d'être publié. open : on peut écrire ; public : les vœux
 *   visibles s'affichent sur la page.
 * - wishes : un message (nom, texte). hidden : masqué par l'organisateur (n'apparaît plus sur la page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wish_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('open')->default(true);
            $table->boolean('public')->default(true);
            $table->timestamps();
        });
        RowLevelSecurity::enable('wish_books');

        Schema::create('wishes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('wish_book_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->text('message');
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->index(['wish_book_id', 'created_at']);
        });
        RowLevelSecurity::enable('wishes');
    }

    public function down(): void
    {
        Schema::dropIfExists('wishes');
        Schema::dropIfExists('wish_books');
    }
};
