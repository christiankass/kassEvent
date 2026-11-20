<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Retrouve l'organisation d'un identifiant reçu sans contexte (lien de commande de l'acheteur,
 * notification d'un prestataire de paiement), pour ouvrir ensuite ce contexte et lire la ligne
 * sous les deux verrous habituels. La table ne contient que des identifiants aléatoires.
 */
final class TenantKeys
{
    public static function remember(string $id, string $organizationId): void
    {
        DB::table('tenant_keys')->insert(['id' => $id, 'organization_id' => $organizationId]);
    }

    public static function organizationOf(?string $id): ?string
    {
        if ($id === null || ! Str::isUuid($id)) {
            return null;
        }

        return DB::table('tenant_keys')->where('id', $id)->value('organization_id');
    }

    /**
     * Exécute $callback dans l'organisation de $id ; null si l'identifiant est inconnu.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function within(?string $id, callable $callback): mixed
    {
        $organizationId = self::organizationOf($id);

        return $organizationId === null ? null : app(TenantContext::class)->run($organizationId, $callback);
    }
}
