<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Événements et types de billets. Montants en unités mineures (centimes).
 * Lecture publique : un événement publié (et ses billets) est visible hors de son organisation,
 * pour sa page de vente ; l'écriture reste limitée à l'organisation courante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 40)->default('Africa/Kinshasa');
            $table->string('venue_name', 160);
            $table->string('venue_address', 255)->nullable();
            $table->string('city', 80);
            $table->unsignedInteger('capacity')->nullable();
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft'); // draft, published
            $table->timestamp('published_at')->nullable();
            $table->string('cover_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'starts_at']);
        });

        RowLevelSecurity::enable('events', readAlso: "status = 'published' AND deleted_at IS NULL");

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('price'); // unités mineures ; 0 = gratuit sur inscription
            $table->unsignedInteger('quantity');
            $table->unsignedSmallInteger('max_per_order')->default(10);
            $table->timestamp('sales_start_at')->nullable();
            $table->timestamp('sales_end_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('event_id');
        });

        RowLevelSecurity::enable(
            'ticket_types',
            readAlso: "event_id IN (SELECT id FROM events WHERE status = 'published' AND deleted_at IS NULL)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('events');
    }
};
