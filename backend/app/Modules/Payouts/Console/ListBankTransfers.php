<?php

namespace App\Modules\Payouts\Console;

use App\Modules\Payments\Support\Amount;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Équipe kassEvent : virements bancaires à faire, avec la destination figée au moment de la demande
 * (jamais le compte actuel de l'organisation, qui a pu changer et être encore dans son délai de sécurité).
 */
final class ListBankTransfers extends Command
{
    protected $signature = 'payouts:bank-transfers';

    protected $description = 'Liste les virements bancaires à faire (puis payouts:complete)';

    public function handle(TenantContext $context): int
    {
        $rows = [];
        foreach (DB::table('organizations')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) as $organization) {
            $context->run($organization->id, function () use ($organization, &$rows) {
                foreach (Payout::where('provider', 'manual')->where('status', Payout::PENDING)->orderBy('id')->get() as $payout) {
                    $rows[] = [
                        $payout->id, $organization->name, Amount::toDecimal($payout->amount).' '.$payout->currency,
                        $payout->bank_name, $payout->account_holder, $payout->account_number, $payout->swift ?? '',
                        $payout->created_at->toDateTimeString(),
                    ];
                }
            });
        }

        $this->table(['Reversement', 'Organisation', 'Montant', 'Banque', 'Titulaire', 'Compte', 'SWIFT', 'Demandé le'], $rows);

        return self::SUCCESS;
    }
}
