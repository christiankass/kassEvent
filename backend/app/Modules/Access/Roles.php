<?php

namespace App\Modules\Access;

use App\Models\User;
use App\Modules\Tenancy\Support\TenantContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles prédéfinis, communs à toutes les organisations (organization_id NULL).
 * Les rôles personnalisés par organisation arrivent en V2.
 */
final class Roles
{
    public const OWNER = 'owner';

    /** Rôles qui touchent à l'argent : double authentification obligatoire pour voir ventes et versements. */
    public const TWO_FACTOR_REQUIRED = [self::OWNER, 'admin', 'finance'];

    public const PERMISSIONS = [
        'organization.update',
        'members.manage',
        'roles.manage',
        'billing.manage',
        'events.view',
        'events.manage',
        'orders.view',
        'orders.refund',
        'payouts.view',
        'checkin.scan',
        'reports.view',
    ];

    /** @return array<string, list<string>> */
    public static function definitions(): array
    {
        return [
            self::OWNER => self::PERMISSIONS,
            'admin' => array_values(array_diff(self::PERMISSIONS, ['billing.manage'])),
            'event_manager' => ['events.view', 'events.manage', 'orders.view', 'checkin.scan', 'reports.view'],
            'finance' => ['events.view', 'orders.view', 'orders.refund', 'payouts.view', 'billing.manage', 'reports.view'],
            'check_in_agent' => ['events.view', 'checkin.scan'],
            'viewer' => ['events.view', 'orders.view', 'reports.view'],
        ];
    }

    /**
     * Rôles qu'on peut donner par invitation ou changement de rôle. Propriétaire exclu :
     * il ne se donne pas et ne se retire pas, l'organisation en garde toujours un.
     *
     * @return list<string>
     */
    public static function assignable(): array
    {
        return array_values(array_diff(array_keys(self::definitions()), [self::OWNER]));
    }

    /**
     * Rôles que cette personne peut donner dans l'organisation courante : seulement ceux dont
     * elle a déjà toutes les permissions (un administrateur ne peut pas donner « finance »,
     * qui gère l'abonnement, pour se l'octroyer par un second compte).
     *
     * @return list<string>
     */
    public static function assignableBy(User $user): array
    {
        $mine = $user->getAllPermissions()->pluck('name')->all();

        return array_values(array_filter(
            self::assignable(),
            fn (string $role) => array_diff(self::definitions()[$role], $mine) === [],
        ));
    }

    /** Crée ou met à jour les rôles et permissions prédéfinis (idempotent). */
    public static function sync(): void
    {
        app(TenantContext::class)->run(null, function () {
            foreach (self::PERMISSIONS as $name) {
                Permission::findOrCreate($name);
            }

            foreach (self::definitions() as $role => $permissions) {
                Role::findOrCreate($role)->syncPermissions($permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
