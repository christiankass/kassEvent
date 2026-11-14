<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fixe l'organisation courante depuis l'en-tête X-Organization-Id.
 * Organisation inconnue, supprimée ou dont l'utilisateur n'est pas membre actif : 404, jamais 403,
 * pour ne pas révéler qu'elle existe.
 *
 * S'utilise à l'intérieur de « tenant.user » (IdentifyUser), qui transmet l'utilisateur à la base
 * et efface le contexte en fin de requête.
 */
final class ResolveOrganization
{
    public const HEADER = 'X-Organization-Id';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->userId() === null) {
            throw new LogicException('Le middleware « tenant » doit suivre « tenant.user ».');
        }

        $id = $request->header(self::HEADER);

        if (! is_string($id) || $id === '') {
            abort(400, __('tenancy.header_missing', ['header' => self::HEADER]));
        }

        $organization = Str::isUuid($id) ? $request->user()->activeOrganizations()->find($id) : null;

        abort_if($organization === null, 404);

        $request->attributes->set('organization', $organization);
        $this->context->set($organization->getKey());

        return $next($request);
    }
}
