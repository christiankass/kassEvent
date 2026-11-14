<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Transmet l'utilisateur connecté à la base (app.user_id) et nettoie le contexte en fin de requête. */
final class IdentifyUser
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->setUser($request->user()?->getAuthIdentifier());

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
