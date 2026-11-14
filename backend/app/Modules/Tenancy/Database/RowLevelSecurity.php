<?php

namespace App\Modules\Tenancy\Database;

use Illuminate\Support\Facades\DB;

/**
 * Second verrou d'isolation : Row-Level Security PostgreSQL.
 *
 * Chaque table métier reçoit une politique qui ne laisse voir et écrire que les lignes
 * de l'organisation fixée par TenantContext (variable de session app.organization_id).
 * FORCE s'applique aussi au propriétaire des tables : seul un superutilisateur ou un
 * rôle BYPASSRLS y échappe, et l'application n'en utilise jamais.
 * PostgreSQL est donc obligatoire : pas de repli silencieux sur un autre moteur.
 */
final class RowLevelSecurity
{
    public static function createContextFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_organization_id() RETURNS uuid
                LANGUAGE sql STABLE
                AS $$ SELECT nullif(current_setting('app.organization_id', true), '')::uuid $$;

            CREATE OR REPLACE FUNCTION app_current_user_id() RETURNS uuid
                LANGUAGE sql STABLE
                AS $$ SELECT nullif(current_setting('app.user_id', true), '')::uuid $$;
        SQL);
    }

    public static function dropContextFunctions(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS app_current_organization_id(); DROP FUNCTION IF EXISTS app_current_user_id();');
    }

    /**
     * @param  string|null  $readAlso  condition SQL supplémentaire autorisée en lecture seulement
     */
    public static function enable(string $table, string $column = 'organization_id', ?string $readAlso = null): void
    {
        $match = "{$column} = app_current_organization_id()";
        $read = $readAlso ? "({$match}) OR ({$readAlso})" : $match;

        DB::unprepared(<<<SQL
            ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
            ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_read ON {$table} FOR SELECT USING ({$read});
            CREATE POLICY tenant_insert ON {$table} FOR INSERT WITH CHECK ({$match});
            CREATE POLICY tenant_update ON {$table} FOR UPDATE USING ({$match}) WITH CHECK ({$match});
            CREATE POLICY tenant_delete ON {$table} FOR DELETE USING ({$match});
        SQL);
    }
}
