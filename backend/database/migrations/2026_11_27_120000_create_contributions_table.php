<?php

use App\Modules\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contributions d'argent sur la page du livre d'or (soutien à la famille pour des obsèques, cadeau
 * pour un anniversaire). kassEvent encaisse, ne prend aucune commission, et reverse le montant à
 * l'organisation avec ses ventes ; les frais du prestataire sont payés en plus par le contributeur.
 *
 * - wish_books.contributions_open : la page propose de contribuer ; beneficiary : à qui va l'argent
 *   (« la famille Mbuyi »), affiché sur la page.
 * - contributions : montant reçu par le bénéficiaire (sans les frais), nom (null : anonyme) et petit mot.
 *   Suivie par le contributeur grâce à un lien personnel (identifiant + clé secrète), comme une commande.
 * - payments : un paiement règle soit une commande, soit une contribution (jamais les deux) ; au plus
 *   un paiement en attente par contribution.
 * - ledger_entries.contribution_id : une contribution n'est comptée qu'une fois au compte de perception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wish_books', function (Blueprint $table) {
            $table->boolean('contributions_open')->default(false);
            $table->string('beneficiary', 80)->nullable();
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('wish_book_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 12)->unique();
            $table->text('access_key'); // chiffrée
            $table->uuid('idempotency_key')->nullable();
            $table->string('status', 20)->default('pending'); // pending, paid
            $table->bigInteger('amount'); // unités mineures, reçu par le bénéficiaire
            $table->char('currency', 3);
            $table->string('contributor_name', 60)->nullable();
            $table->string('message', 280)->nullable();
            $table->string('locale', 5);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['wish_book_id', 'idempotency_key']);
            $table->index(['wish_book_id', 'status', 'paid_at']);
        });
        DB::statement("ALTER TABLE contributions ADD CONSTRAINT contributions_status_check CHECK (status IN ('pending', 'paid'))");
        DB::statement('ALTER TABLE contributions ADD CONSTRAINT contributions_amount_check CHECK (amount > 0)');
        RowLevelSecurity::enable('contributions');

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('order_id')->nullable()->change();
            $table->foreignUuid('contribution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index('contribution_id');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_one_owner_check CHECK (num_nonnulls(order_id, contribution_id) = 1)');
        DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_contribution ON payments (contribution_id) WHERE status = 'pending'");

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->uuid('contribution_id')->nullable(); // module Contributions, pas de clé étrangère
            $table->unique(['contribution_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['contribution_id', 'type']);
            $table->dropColumn('contribution_id');
        });
        DB::statement('DROP INDEX IF EXISTS payments_one_pending_per_contribution');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_one_owner_check');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contribution_id');
        });
        Schema::dropIfExists('contributions');
        Schema::table('wish_books', function (Blueprint $table) {
            $table->dropColumn(['contributions_open', 'beneficiary']);
        });
    }
};
