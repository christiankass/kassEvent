<?php

use App\Modules\Access\Roles;
use App\Modules\Payouts\Support\Commission;
use App\Modules\Tenancy\Database\RowLevelSecurity;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Compte de perception de chaque organisation.
 *
 * - ledger_entries : journal en écriture seule, montants signés en unités mineures. Le solde d'une
 *   organisation dans une devise est la somme de ses lignes ; une vente n'est reversable qu'à partir
 *   de available_at.
 * - payout_accounts : où l'organisation reçoit son argent (mobile money ou banque), un par organisation.
 * - payouts : chaque reversement ; au plus un en cours par organisation et par devise (garanti par la base).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // sale, commission, payout, payout_reversal
            $table->char('currency', 3);
            $table->bigInteger('amount'); // signé : + crédit, − débit
            $table->uuid('order_id')->nullable(); // vente et commission (module Ticketing, pas de clé étrangère)
            $table->uuid('payout_id')->nullable();
            $table->string('reference', 12)->nullable(); // référence de la commande
            $table->string('label', 160)->nullable(); // titre de l'événement au moment de la vente
            $table->unsignedSmallInteger('tickets')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'currency', 'available_at']);
            // Une commande n'est comptée qu'une fois.
            $table->unique(['order_id', 'type']);
        });
        RowLevelSecurity::enable('ledger_entries');

        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('method', 20); // mobile_money, bank
            $table->string('operator', 20)->nullable(); // vodacom, orange, airtel
            $table->string('phone', 20)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('account_holder', 120)->nullable();
            $table->text('account_number')->nullable(); // chiffré
            $table->string('swift', 11)->nullable();
            $table->timestamp('active_from'); // reversements vers ce compte à partir de cette date
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        RowLevelSecurity::enable('payout_accounts');

        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->string('method', 20); // mobile_money, bank
            $table->string('provider', 20); // pawapay, simulated, manual
            $table->string('status', 20)->default('pending'); // pending, succeeded, failed
            $table->string('trigger', 20); // automatic, manual
            // Destination au moment du reversement (le compte peut changer ensuite).
            $table->string('operator', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('account_last4', 4)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'currency', 'status']);
        });
        RowLevelSecurity::enable('payouts');
        DB::statement("CREATE UNIQUE INDEX payouts_one_pending_per_currency ON payouts (organization_id, currency) WHERE status = 'pending'");

        // Nouvelle permission payouts.manage (propriétaire, finance).
        Roles::sync();

        // Ventes déjà payées avant ce journal : comptées, et reversables tout de suite.
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            app(TenantContext::class)->run($organizationId, function () {
                $orders = DB::table('orders')->join('events', 'events.id', '=', 'orders.event_id')
                    ->where('orders.status', 'paid')->where('orders.total', '>', 0)
                    ->select('orders.id', 'orders.organization_id', 'orders.reference', 'orders.currency', 'orders.total', 'orders.paid_at', 'events.title')
                    ->get();

                foreach ($orders as $order) {
                    $tickets = (int) DB::table('order_items')->where('order_id', $order->id)->where('unit_price', '>', 0)->sum('quantity');
                    $common = [
                        'organization_id' => $order->organization_id, 'currency' => $order->currency, 'order_id' => $order->id,
                        'reference' => $order->reference, 'label' => Str::limit($order->title, 157), 'tickets' => $tickets,
                        'available_at' => now(), 'created_at' => $order->paid_at ?? now(),
                    ];
                    DB::table('ledger_entries')->insert([
                        ['id' => Str::uuid7(), 'type' => 'sale', 'amount' => (int) $order->total, ...$common],
                        ['id' => Str::uuid7(), 'type' => 'commission', 'amount' => -Commission::for((int) $order->total, $order->currency, $tickets), ...$common],
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_accounts');
        Schema::dropIfExists('ledger_entries');
    }
};
