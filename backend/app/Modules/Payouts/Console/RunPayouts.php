<?php

namespace App\Modules\Payouts\Console;

use App\Modules\Payouts\Support\PayoutService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Reversements automatiques de toutes les organisations (planifié toutes les heures). */
final class RunPayouts extends Command
{
    protected $signature = 'payouts:run';

    protected $description = 'Vérifie les reversements en cours et reverse les soldes disponibles aux organisations';

    public function handle(TenantContext $context, PayoutService $payouts): int
    {
        DB::table('organizations')->whereNull('deleted_at')->orderBy('id')->pluck('id')
            ->each(function (string $organizationId) use ($context, $payouts) {
                try {
                    $context->run($organizationId, fn () => $payouts->runAutomatic());
                } catch (Throwable $e) {
                    report($e); // une organisation en erreur n'empêche pas les suivantes
                }
            });

        return self::SUCCESS;
    }
}
