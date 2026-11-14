<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'kassevent-api',
        'version' => 'v1',
    ]))->name('api.v1.health');

    Route::middleware('auth:sanctum')->get('/me', fn (Request $request) => $request->user())
        ->name('api.v1.me');
});
