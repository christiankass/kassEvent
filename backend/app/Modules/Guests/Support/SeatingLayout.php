<?php

namespace App\Modules\Guests\Support;

/**
 * Calcul des tables depuis la capacité de la salle : soit les places par table sont choisies
 * (10 par défaut, une table ronde) et le nombre de tables en découle, soit c'est le nombre de
 * tables, et les places par table en découlent. Les places sont réparties le plus également
 * possible : 95 personnes à 10 par table donnent 10 tables, 5 de 10 places et 5 de 9.
 */
final class SeatingLayout
{
    public const DEFAULT_SEATS = 10;

    public const MIN_SEATS = 2;

    public const MAX_SEATS = 30;

    public const MAX_TABLES = 300;

    public const MAX_CAPACITY = 5000;

    /** @return list<int> places de chaque table, de la table 1 à la dernière */
    public static function seats(int $capacity, ?int $seatsPerTable = null, ?int $tableCount = null): array
    {
        $tables = $tableCount ?? (int) ceil($capacity / ($seatsPerTable ?? self::DEFAULT_SEATS));
        $tables = max(1, min($tables, $capacity));
        $base = intdiv($capacity, $tables);
        $extra = $capacity % $tables;

        return array_map(fn (int $i) => $base + ($i < $extra ? 1 : 0), range(0, $tables - 1));
    }
}
