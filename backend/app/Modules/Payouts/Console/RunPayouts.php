<?php

namespace App\Modules\Payouts\Console;

use App\Modules\Payouts\Support\PayoutService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/** Reversements automatiques de toutes les organisations (planifié toutes les heures). */
final class RunPayouts extends Command
{
    protected $signature = 'payouts:run';

    protected $description = 'Vérifie les reversements en cours et reverse les soldes disponibles aux organisations';

    public function handle(TenantContext $context, PayoutService $payouts): int
    {
        $context->eachOrganization(fn () => $payouts->runAutomatic());

        return self::SUCCESS;
    }
}
