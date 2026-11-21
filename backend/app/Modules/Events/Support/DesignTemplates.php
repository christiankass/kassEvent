<?php

namespace App\Modules\Events\Support;

/**
 * Modèles de départ de l'atelier : un choix de type, de couleurs et de police que l'organisateur
 * ajuste ensuite librement. Les couleurs viennent de la palette kassEvent (docs/DESIGN.md).
 */
final class DesignTemplates
{
    public const DEFAULT = 'kassevent';

    /** @var array<string, array{kind: string, primary_color: string, accent_color: string, font: string}> */
    private const TEMPLATES = [
        'kassevent' => ['kind' => 'ticket', 'primary_color' => '#0B3B3C', 'accent_color' => '#FFC83D', 'font' => 'fraunces'],
        'concert' => ['kind' => 'ticket', 'primary_color' => '#5B3CC4', 'accent_color' => '#FF7A59', 'font' => 'fraunces'],
        'entreprise' => ['kind' => 'ticket', 'primary_color' => '#0F6890', 'accent_color' => '#2BA3D8', 'font' => 'instrument'],
        'mariage' => ['kind' => 'invitation', 'primary_color' => '#6B1E3A', 'accent_color' => '#D4A24C', 'font' => 'playfair'],
        'anniversaire' => ['kind' => 'invitation', 'primary_color' => '#A3245A', 'accent_color' => '#FFC83D', 'font' => 'script'],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /** @return array{kind: string, primary_color: string, accent_color: string, font: string} */
    public static function get(string $name): array
    {
        return self::TEMPLATES[$name];
    }

    /** @return array<string, array{kind: string, primary_color: string, accent_color: string, font: string}> */
    public static function all(): array
    {
        return self::TEMPLATES;
    }
}
