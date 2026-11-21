<?php

use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Modules\Payments\Http\Controllers\SimulatorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Page de paiement par carte simulée : seulement hors production, quand un moyen est en mode « simulated ».
if (GatewayRegistry::simulationEnabled()) {
    Route::middleware('signed')->prefix('simulation/paiements/{payment}')->name('payments.simulator.')->whereUuid('payment')->group(function () {
        Route::get('/', [SimulatorController::class, 'show'])->name('show');
        Route::post('/', [SimulatorController::class, 'complete'])->name('complete');
    });
}
