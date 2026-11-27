<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ton du livre de vœux, pour tout type d'événement : celebration (anniversaire, mariage, naissance :
 * vœux et félicitations), tribute (obsèques, deuil, hommage : condoléances, page sobre), guestbook
 * (conférence, culte, soirée : livre d'or). Les livres déjà ouverts restent en celebration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wish_books', function (Blueprint $table) {
            $table->string('tone', 20)->default('celebration');
        });
        DB::statement("ALTER TABLE wish_books ADD CONSTRAINT wish_books_tone_check CHECK (tone IN ('celebration', 'tribute', 'guestbook'))");
    }

    public function down(): void
    {
        Schema::table('wish_books', function (Blueprint $table) {
            $table->dropColumn('tone');
        });
    }
};
