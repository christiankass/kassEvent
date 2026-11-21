<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logo et bannière de l'atelier, réduits une fois à l'envoi : ils partent dans chaque e-mail de
 * billets, où une photo de 5 Mo n'a rien à faire. PNG gardé en PNG (transparence), le reste en JPEG,
 * que toutes les messageries affichent (contrairement au WebP).
 */
final class DesignImage
{
    /** Au-delà, le décodage par GD demanderait des centaines de Mo de mémoire (image très compressée). */
    private const MAX_PIXELS = 40_000_000;

    /** @return string chemin enregistré sur le disque des médias */
    public static function store(UploadedFile $file, string $directory, int $maxWidth, int $maxHeight, int $quality = 85): string
    {
        $size = @getimagesize($file->getRealPath());
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['image' => __('designs.image_too_large')]);
        }
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw ValidationException::withMessages(['image' => __('validation.image', ['attribute' => 'image'])]);
        }
        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);

        $image = $scale < 1 ? imagescale($source, (int) round($width * $scale), (int) round($height * $scale)) : $source;
        $png = $file->getMimeType() === 'image/png';
        if ($png) {
            imagesavealpha($image, true);
        }

        ob_start();
        $png ? imagepng($image, null, 9) : imagejpeg($image, null, $quality);
        $path = $directory.'/'.Str::random(40).($png ? '.png' : '.jpg');
        Event::mediaDisk()->put($path, (string) ob_get_clean());

        return $path;
    }
}
