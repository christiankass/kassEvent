<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes, billets émis et paiements. Montants en unités mineures, dans la devise de l'événement.
 *
 * tenant_keys : index global « identifiant → organisation » pour les rares requêtes qui arrivent
 * sans organisation (page de commande de l'acheteur, notifications de pawaPay et Stripe). Il ne contient
 * que des identifiants aléatoires ; la lecture de la commande elle-même reste soumise à la RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // Un événement ou un type de billet vendu ne se supprime plus.
            $table->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $table->string('reference', 12)->unique();
            $table->string('status', 20)->default('pending'); // pending, paid
            $table->char('currency', 3);
            $table->unsignedBigInteger('total');
            $table->string('buyer_name', 120);
            $table->string('buyer_email', 254);
            $table->string('locale', 5)->default('fr');
            $table->text('access_key'); // chiffrée : clé du lien de la commande envoyé à l'acheteur
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'event_id', 'status']);
        });
        RowLevelSecurity::enable('orders');

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->restrictOnDelete();
            $table->string('name', 80); // nom du billet au moment de l'achat
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->timestamps();

            $table->index('ticket_type_id');
        });
        RowLevelSecurity::enable('order_items');

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique(); // contenu du QR code
            $table->string('holder_name', 120);
            $table->timestamps();

            $table->index('order_id');
        });
        RowLevelSecurity::enable('tickets');

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20); // mobile_money, card
            $table->string('provider', 20); // pawapay, stripe, simulated
            $table->string('status', 20)->default('pending'); // pending, succeeded, failed
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('operator', 20)->nullable(); // vodacom, orange, airtel
            $table->string('phone', 20)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
        RowLevelSecurity::enable('payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('tenant_keys');
    }
};
