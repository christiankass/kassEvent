<?php

namespace App\Modules\Ticketing\Support;

use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\Color;
use App\Modules\Ticketing\Models\Ticket;
use GdImage;

/**
 * Billet simple de l'atelier en image (JPEG 1080 × 1350), pour les messageries qui n'affichent
 * pas l'HTML de l'e-mail : bandeau aux couleurs de l'organisateur, puis une carte blanche avec
 * le QR code (toujours noir sur blanc), le titulaire, le type de billet et le code.
 */
final class ClassicTicketImage
{
    private const WIDTH = 1080;

    private const HEIGHT = 1350;

    private const MARGIN = 80;

    /** @param  string  $when  date de l'événement, déjà mise en forme dans la langue de l'acheteur */
    public static function render(EventDesign $design, Ticket $ticket, string $when): string
    {
        $event = $design->event;
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $color = fn (string $hex) => imagecolorallocate($image, ...Color::rgb($hex));
        [$regular, $bold] = array_map(
            fn (string $file) => resource_path("fonts/{$file}.ttf"),
            TicketImage::FONT_FILES[$design->font] ?? TicketImage::FONT_FILES['fraunces'],
        );
        $sans = resource_path('fonts/InstrumentSans-Regular.ttf');
        $sansBold = resource_path('fonts/InstrumentSans-Bold.ttf');
        $mono = resource_path('fonts/JetBrainsMono-Bold.ttf');
        $onPrimary = $color(Color::textOn($design->primary_color));
        $ink = $color(Color::DARK);

        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $color($design->primary_color));

        // Bandeau : organisation, titre du billet, nom de l'événement, date et lieu.
        $y = 130;
        self::line($image, $event->organization->name, $sansBold, 28, $onPrimary, $y);
        $y += 64;
        self::line($image, $design->headlineText(), $regular, 34, $onPrimary, $y);
        foreach (self::wrap($event->title, $bold, 64, 3) as $titleLine) {
            $y += 84;
            self::line($image, $titleLine, $bold, 64, $onPrimary, $y);
        }
        $y += 64;
        self::line($image, $when, $sans, 30, $onPrimary, $y);
        $y += 46;
        self::line($image, "{$event->venue_name}, {$event->city}", $sans, 30, $onPrimary, $y);

        // Carte blanche : QR code, titulaire, type et code.
        $top = $y + 50;
        imagefilledrectangle($image, self::MARGIN, $top, self::WIDTH - self::MARGIN, self::HEIGHT - self::MARGIN, $color(Color::LIGHT));
        imagefilledrectangle($image, self::MARGIN, $top, self::WIDTH - self::MARGIN, $top + 14, $color($design->accent_color));
        $bottom = self::HEIGHT - self::MARGIN;
        $qrSize = max(360, min(560, $bottom - $top - 250));
        $qr = imagecreatefromstring(QrCode::png($ticket->code, $qrSize));
        imagecopy($image, $qr, (int) ((self::WIDTH - imagesx($qr)) / 2), $top + 40, 0, 0, imagesx($qr), imagesy($qr));

        $y = $top + 40 + imagesy($qr) + 56;
        self::line($image, $ticket->holder_name, $sansBold, 40, $ink, $y, center: true);
        $y += 52;
        self::line($image, $ticket->label(), $sans, 30, $ink, $y, center: true);
        $y += 52;
        self::line($image, TicketCode::format($ticket->code), $mono, 30, $ink, $y, center: true);

        ob_start();
        imagejpeg($image, null, 88);

        return (string) ob_get_clean();
    }

    /** Une ligne de texte (taille en pixels), réduite si elle dépasse la largeur utile. */
    private static function line(GdImage $image, string $text, string $font, float $px, int $color, int $y, bool $center = false): void
    {
        $text = trim($text);
        $max = self::WIDTH - 2 * self::MARGIN - ($center ? 80 : 0);
        while ($px > 12 && self::width($text, $font, $px) > $max) {
            $px *= 0.94;
        }
        $x = $center ? (self::WIDTH - self::width($text, $font, $px)) / 2 : self::MARGIN;
        imagettftext($image, $px * 0.75, 0, (int) round($x), $y, $color, $font, $text);
    }

    /** @return list<string> au plus $lines lignes ; la dernière garde le reste (réduite par line()). */
    private static function wrap(string $text, string $font, float $px, int $lines): array
    {
        $result = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";
            if ($current !== '' && count($result) < $lines - 1 && self::width($candidate, $font, $px) > self::WIDTH - 2 * self::MARGIN) {
                $result[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        return [...$result, $current];
    }

    private static function width(string $text, string $font, float $px): float
    {
        $box = imagettfbbox($px * 0.75, 0, $font, $text);

        return $box[2] - $box[0];
    }
}
