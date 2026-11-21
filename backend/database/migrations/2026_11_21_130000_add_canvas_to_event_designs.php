<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Éditeur libre (studio) : le billet peut être un design fait en glisser-déposer. Le document
 * (calques) est gardé pour être modifié ; son image fixe sert de fond au billet de chaque invité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_designs', function (Blueprint $table) {
            $table->string('mode', 10)->default('classic'); // classic (atelier), canvas (studio)
            $table->jsonb('canvas')->nullable();
            $table->string('canvas_image_path')->nullable();
            $table->jsonb('poster_canvas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_designs', function (Blueprint $table) {
            $table->dropColumn(['mode', 'canvas', 'canvas_image_path', 'poster_canvas']);
        });
    }
};
