<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invitations de collaborateurs : une seule invitation en attente par adresse et par organisation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('role', 40);
            $table->char('token_hash', 64)->unique(); // SHA-256 du jeton : le jeton lui-même n'est jamais stocké
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
        });

        RowLevelSecurity::enable('invitations');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
