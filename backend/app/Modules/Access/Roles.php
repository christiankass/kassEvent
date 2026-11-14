<?php

namespace App\Modules\Access;

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
