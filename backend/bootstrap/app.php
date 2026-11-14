<?php

use App\Http\Middleware\SetLocale;
use App\Modules\Tenancy\Http\Middleware\IdentifyUser;
use App\Modules\Tenancy\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sessions par cookie pour le site (app.kassevent.com), jetons pour les futures applications.
        $middleware->statefulApi();
        $middleware->api(prepend: [SetLocale::class]);

        $middleware->alias([
            'tenant.user' => IdentifyUser::class,
            'tenant' => ResolveOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
