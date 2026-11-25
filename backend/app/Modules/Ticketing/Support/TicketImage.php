<?php

namespace App\Modules\Ticketing\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\Color;
use App\Modules\Ticketing\Models\Ticket;
use GdImage;

/**
 * Billet fait dans le studio : l'image fixe du design, plus ce qui change d'un billet à l'autre,
 * écrit à l'emplacement choisi par l'organisateur (QR code noir sur fond blanc, nom de l'invité,
 * type de billet, code). Résultat en JPEG, pour l'e-mail et la page de commande.
 */
final class TicketImage
{
    /** Fichiers de police (resources/fonts) : [normal, gras]. */
    public const FONT_FILES = [
        'fraunces' => ['Fraunces-Regular', 'Fraunces-Bold'],
        'playfair' => ['PlayfairDisplay-Regular', 'PlayfairDisplay-Bold'],
        'instrument' => ['InstrumentSans-Regular', 'InstrumentSans-Bold'],
        'script' => ['GreatVibes-Regular', 'GreatVibes-Regular'],
        'mono' => ['JetBrainsMono-Regular', 'JetBrainsMono-Bold'],
        'anton' => ['Anton-Regular', 'Anton-Regular'],
        'poppins' => ['Poppins-Regular', 'Poppins-ExtraBold'],
        'fredoka' => ['Fredoka-Regular', 'Fredoka-Bold'],
        'cinzel' => ['Cinzel-Regular', 'Cinzel-Bold'],
        'pacifico' => ['Pacifico-Regular', 'Pacifico-Regular'],
    ];

    /** Images fixes déjà décodées, par chemin : une commande de N billets ne lit le fond qu'une fois. */
    private static array $backgrounds = [];

    /** Image du billet d'un participant (null : billet simple de l'atelier). */
    public static function forTicket(EventDesign $design, Ticket $ticket): ?string
    {
        return self::render($design, $ticket->code, [
            'holder' => $ticket->holder_name,
            'ticket_type' => $ticket->label(),
            'code' => TicketCode::format($ticket->code),
        ]);
    }

    /**
     * @param  array{holder: string, ticket_type: string, code: string}  $values
     * @return string|null JPEG, ou null si le design n'est pas un design du studio
     */
    public static function render(EventDesign $design, string $code, array $values): ?string
    {
        $background = self::background($design);
        if (! $background) {
            return null;
        }
        $image = imagecreatetruecolor(imagesx($background), imagesy($background));
        imagecopy($image, $background, 0, 0, 0, 0, imagesx($background), imagesy($background));
        imagealphablending($image, true);

        foreach ($design->canvas['elements'] as $element) {
            match ($element['type']) {
                'qr' => self::qr($image, $element, $code),
                'field' => self::text($image, $element, $values[$element['field']] ?? ''),
                default => null,
            };
        }

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private static function background(EventDesign $design): ?GdImage
    {
        if (! $design->usesCanvas()) {
            return null;
        }
        $path = $design->canvas_image_path;
        if (! array_key_exists($path, self::$backgrounds)) {
            $data = Event::mediaDisk()->get($path);
            // Un seul fond gardé : un worker enchaîne des commandes d'événements différents.
            self::$backgrounds = [$path => $data ? (imagecreatefromstring($data) ?: null) : null];
        }

        return self::$backgrounds[$path];
    }

    /** QR code sur fond blanc, avec sa marge de silence : il se scanne quelle que soit l'image autour. */
    private static function qr(GdImage $image, array $element, string $code): void
    {
        $size = (int) round($element['width']);
        $x = (int) round($element['x']);
        $y = (int) round($element['y']);
        $qr = imagecreatefromstring(QrCode::png($code, $size));

        imagecopy($image, $qr, $x, $y, 0, 0, imagesx($qr), imagesy($qr));
    }

    /**
     * Texte d'un champ, dans sa boîte : réduit si trop long pour la largeur, aligné comme dans
     * l'éditeur et tourné autour du coin haut-gauche (comme le fait l'éditeur).
     */
    private static function text(GdImage $image, array $element, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $font = resource_path('fonts/'.self::FONT_FILES[$element['font']][$element['bold'] ? 1 : 0].'.ttf');
        $px = $element['fontSize'];
        // GD mesure en points (96 ppp) : 1 px = 0,75 pt.
        $points = fn (float $px) => $px * 0.75;
        $width = fn (float $px) => (($box = imagettfbbox($points($px), 0, $font, $text))[2] - $box[0]);

        while ($px > 6 && $width($px) > $element['width']) {
            $px *= 0.95;
        }
        $offset = match ($element['align']) {
            'center' => ($element['width'] - $width($px)) / 2,
            'right' => $element['width'] - $width($px),
            default => 0,
        };

        // Ligne de base comme dans l'éditeur (texte centré dans sa hauteur de ligne), dans le repère
        // du calque, puis tournée autour de son coin.
        [$lx, $ly] = [$offset, $px * $element['lineHeight'] / 2 + $px * 0.35];
        $angle = deg2rad($element['rotation']);
        $x = $element['x'] + $lx * cos($angle) - $ly * sin($angle);
        $y = $element['y'] + $lx * sin($angle) + $ly * cos($angle);

        [$r, $g, $b] = Color::rgb($element['fill']);
        $alpha = (int) round(127 * (1 - $element['opacity']));
        imagettftext($image, $points($px), -$element['rotation'], (int) round($x), (int) round($y), imagecolorallocatealpha($image, $r, $g, $b, $alpha), $font, $text);
    }
}
