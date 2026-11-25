<?php

namespace App\Modules\Payouts\Console;

use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Support\PayoutService;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Console\Command;

/** Équipe kassEvent : enregistre un virement bancaire fait à la main (ou son échec). */
final class CompletePayout extends Command
{
    protected $signature = 'payouts:complete {payout : identifiant du reversement}
        {--reference= : référence du virement chez la banque}
        {--failed= : code d\'échec (le montant revient au solde de l\'organisation)}';

    protected $description = 'Enregistre le résultat d\'un reversement par virement bancaire';

    public function handle(PayoutService $payouts): int
    {
        $id = (string) $this->argument('payout');

        $done = TenantKeys::within($id, function () use ($id, $payouts) {
            $payout = Payout::where('provider', 'manual')->where('status', Payout::PENDING)->find($id);
            if ($payout === null) {
                return false;
            }
            $failed = $this->option('failed');
            $payouts->settle(
                $payout,
                $failed ? GatewayStatus::failed((string) $failed) : GatewayStatus::succeeded($payout->amount, $payout->currency),
                $this->option('reference') ?: null,
            );

            return true;
        });

        if (! $done) {
            $this->error('Aucun virement bancaire en attente avec cet identifiant.');

            return self::FAILURE;
        }
        $this->info('Reversement enregistré.');

        return self::SUCCESS;
    }
}
