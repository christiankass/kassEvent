<?php

namespace App\Modules\Events\Support;

use Illuminate\Validation\ValidationException;

/**
 * Document de l'éditeur libre : un fond et des calques (textes, formes, images, QR code, champs
 * propres à chaque billet). Tout est vérifié ici, champ par champ, avant d'être enregistré :
 * couleurs hexadécimales, polices et types connus, images de l'événement seulement (jamais d'URL),
 * et pour un billet, un QR code et un seul, droit, assez grand et entièrement visible.
 */
final class CanvasDocument
{
    public const TICKET = 'ticket';

    public const POSTER = 'poster';

    /**
     * Formats de page (pixels) : portrait 4:5, carré, story 9:16, paysage 1,91:1 (partages) et ticket
     * en bande, talon détachable à droite.
     */
    public const FORMATS = ['portrait' => [1080, 1350], 'square' => [1080, 1080], 'story' => [1080, 1920], 'landscape' => [1200, 630], 'strip' => [1660, 640]];

    /** Formats permis pour chaque usage, le premier par défaut. */
    public const USAGE_FORMATS = [
        self::TICKET => ['portrait', 'strip', 'square', 'story'],
        self::POSTER => ['landscape', 'portrait', 'square', 'story'],
    ];

    /** Champs écrits sur le billet de chaque invité. */
    public const FIELDS = ['holder', 'ticket_type', 'code'];

    /** Polices de l'éditeur ; leurs fichiers sont dans resources/fonts (voir TicketImage). */
    public const FONTS = ['fraunces', 'playfair', 'instrument', 'script', 'mono', 'anton', 'poppins', 'fredoka', 'cinzel', 'pacifico'];

    /** Pictogrammes dessinés par l'éditeur (dans l'image fixe : rien à faire côté serveur). */
    public const ICONS = [
        'calendar', 'clock', 'pin', 'phone', 'globe', 'ticket', 'music', 'mic', 'heart', 'live', 'cake', 'rings',
        'cross', 'trophy', 'check', 'arrow', 'chevrons', 'quote', 'play', 'mail', 'user', 'sparkle', 'gift', 'church',
    ];

    public const ASSET = '/^[A-Za-z0-9]{40}\.(png|jpg)$/';

    private const TYPES = ['text', 'field', 'rect', 'ellipse', 'star', 'line', 'image', 'qr', 'icon'];

    private const MAX_ELEMENTS = 150;

    /** Côté minimal du QR code, en part du plus petit côté de la page : il doit rester lisible. */
    private const QR_MIN_SHARE = 0.15;

    /**
     * @param  callable(string): bool  $assetExists  l'image appartient bien à l'événement
     * @return array<string, mixed> document nettoyé (seules les clés connues sont gardées)
     */
    public static function validate(mixed $document, string $usage, callable $assetExists): array
    {
        $errors = [];
        $fail = function (string $message) use (&$errors) {
            $errors[] = $message;
        };

        if (! is_array($document) || ! is_array($document['elements'] ?? null) || ! is_array($document['background'] ?? null)) {
            throw ValidationException::withMessages(['canvas' => __('studio.invalid')]);
        }
        $size = [$document['width'] ?? null, $document['height'] ?? null];
        $format = self::formatOf($size, $usage);
        if ($format === null) {
            $fail(__('studio.invalid'));
        }
        [$width, $height] = self::FORMATS[$format ?? self::USAGE_FORMATS[$usage][0]];
        if (count($document['elements']) > self::MAX_ELEMENTS) {
            $fail(__('studio.too_many', ['max' => self::MAX_ELEMENTS]));
        }

        $background = [
            'color' => self::color($document['background']['color'] ?? null, $fail) ?? '#FFFFFF',
            'image' => self::asset($document['background']['image'] ?? null, $assetExists, $fail),
        ];

        $elements = [];
        foreach (array_slice($document['elements'], 0, self::MAX_ELEMENTS) as $element) {
            $elements[] = self::element(is_array($element) ? $element : [], $assetExists, $fail);
        }

        $qrs = array_values(array_filter($elements, fn ($element) => $element['type'] === 'qr'));
        $fields = array_filter($elements, fn ($element) => $element['type'] === 'field');
        if ($usage === self::POSTER && ($qrs !== [] || $fields !== [])) {
            $fail(__('studio.poster_no_ticket_fields'));
        }
        if ($usage === self::TICKET) {
            if (count($qrs) !== 1) {
                $fail(__('studio.one_qr'));
            } else {
                $qr = $qrs[0];
                $min = (int) ceil(min($width, $height) * self::QR_MIN_SHARE);
                if ($qr['width'] < $min || abs($qr['width'] - $qr['height']) > 1) {
                    $fail(__('studio.qr_size', ['min' => $min]));
                }
                if ($qr['rotation'] != 0 || $qr['x'] < 0 || $qr['y'] < 0 || $width < $qr['x'] + $qr['width'] || $height < $qr['y'] + $qr['height']) {
                    $fail(__('studio.qr_inside'));
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['canvas' => array_values(array_unique($errors))]);
        }

        return ['width' => $width, 'height' => $height, 'background' => $background, 'elements' => $elements];
    }

    /** Nom du format d'une page [largeur, hauteur], s'il est permis pour cet usage. */
    public static function formatOf(array $size, string $usage): ?string
    {
        foreach (self::USAGE_FORMATS[$usage] as $format) {
            if (self::FORMATS[$format] === $size) {
                return $format;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function element(array $element, callable $assetExists, callable $fail): array
    {
        $type = $element['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            $fail(__('studio.invalid'));

            return ['type' => 'rect', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'rotation' => 0];
        }

        $clean = [
            'id' => is_string($element['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $element['id']) ? $element['id'] : bin2hex(random_bytes(6)),
            'type' => $type,
            'x' => self::number($element['x'] ?? null, -10000, 10000, $fail),
            'y' => self::number($element['y'] ?? null, -10000, 10000, $fail),
            'width' => self::number($element['width'] ?? null, 1, 10000, $fail),
            'height' => self::number($element['height'] ?? null, 1, 10000, $fail),
            'rotation' => self::number($element['rotation'] ?? 0, -360, 360, $fail),
            'opacity' => self::number($element['opacity'] ?? 1, 0, 1, $fail),
            'name' => is_string($element['name'] ?? null) ? mb_substr($element['name'], 0, 60) : null,
        ];

        return match ($type) {
            'text', 'field' => [...$clean, ...self::textStyle($element, $fail), ...($type === 'text'
                ? ['text' => self::text($element['text'] ?? '', 500, $fail)]
                : ['field' => in_array($element['field'] ?? null, self::FIELDS, true) ? $element['field'] : ($fail(__('studio.invalid')) ?? 'holder')])],
            'rect', 'ellipse', 'star', 'line' => [...$clean,
                'fill' => self::color($element['fill'] ?? null, $fail),
                'fill2' => self::color($element['fill2'] ?? null, $fail),
                'stroke' => self::color($element['stroke'] ?? null, $fail),
                'strokeWidth' => self::number($element['strokeWidth'] ?? 0, 0, 200, $fail),
                'cornerRadius' => self::number($element['cornerRadius'] ?? 0, 0, 5000, $fail),
            ],
            'image' => [...$clean,
                'asset' => self::asset($element['asset'] ?? null, $assetExists, $fail),
                'cornerRadius' => self::number($element['cornerRadius'] ?? 0, 0, 5000, $fail),
                'fit' => self::oneOf($element['fit'] ?? 'cover', ['cover', 'contain'], $fail),
            ],
            'icon' => [...$clean,
                'icon' => self::oneOf($element['icon'] ?? null, self::ICONS, $fail),
                'fill' => self::color($element['fill'] ?? '#0E1B1C', $fail) ?? '#0E1B1C',
            ],
            'qr' => $clean,
        };
    }

    /** @return array<string, mixed> */
    private static function textStyle(array $element, callable $fail): array
    {
        $font = $element['font'] ?? null;

        return [
            'font' => in_array($font, self::FONTS, true) ? $font : ($fail(__('studio.invalid')) ?? 'instrument'),
            'fontSize' => self::number($element['fontSize'] ?? 32, 6, 600, $fail),
            'bold' => (bool) ($element['bold'] ?? false),
            'italic' => (bool) ($element['italic'] ?? false),
            'fill' => self::color($element['fill'] ?? '#0E1B1C', $fail) ?? '#0E1B1C',
            'align' => in_array($element['align'] ?? 'left', ['left', 'center', 'right'], true) ? $element['align'] ?? 'left' : 'left',
            'lineHeight' => self::number($element['lineHeight'] ?? 1.2, 0.6, 4, $fail),
            'letterSpacing' => self::number($element['letterSpacing'] ?? 0, -20, 100, $fail),
        ];
    }

    /** Valeur prise dans une liste fermée (la première de la liste si elle est inconnue). */
    private static function oneOf(mixed $value, array $allowed, callable $fail): string
    {
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        $fail(__('studio.invalid'));

        return $allowed[0];
    }

    private static function number(mixed $value, float $min, float $max, callable $fail): float
    {
        if (! is_int($value) && ! is_float($value)) {
            $fail(__('studio.invalid'));

            return $min;
        }

        return round(max($min, min($max, (float) $value)), 2);
    }

    private static function color(mixed $value, callable $fail): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^#[0-9A-Fa-f]{6}\z/', $value)) {
            $fail(__('studio.invalid'));

            return null;
        }

        return strtoupper($value);
    }

    private static function text(mixed $value, int $max, callable $fail): string
    {
        if (! is_string($value) || mb_strlen($value) > $max) {
            $fail(__('studio.text_too_long', ['max' => $max]));

            return '';
        }

        return $value;
    }

    private static function asset(mixed $value, callable $assetExists, callable $fail): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match(self::ASSET, $value) || ! $assetExists($value)) {
            $fail(__('studio.unknown_image'));

            return null;
        }

        return $value;
    }
}
