<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reversements automatiques aux organisations : on reverse vite (argent de tiers détenu par kassEvent).
Schedule::command('payouts:run')->hourly()->withoutOverlapping()->onOneServer();
