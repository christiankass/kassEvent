<?php

namespace App\Modules\Ticketing\Support;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR code d'un billet : SVG pour le site, PNG pour les e-mails (les messageries n'affichent pas le SVG). */
final class QrCode
{
    public static function svg(string $content): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd)))->writeString($content);
    }

    public static function png(string $content): string
    {
        return (new Writer(new GDLibRenderer(360, 2)))->writeString($content);
    }
}
