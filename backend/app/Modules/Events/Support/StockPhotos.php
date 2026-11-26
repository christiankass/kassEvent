<?php

namespace App\Modules\Events\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Banque de photos libres du studio : Pexels ou Unsplash, par leur API officielle (leurs licences
 * permettent l'usage commercial ; leurs conditions demandent de passer par l'API et de créditer
 * l'auteur). La clé reste sur le serveur. Les résultats sont gardés en cache : la galerie de modèles
 * demande les mêmes thèmes à chaque organisateur, et les quotas des API sont faibles.
 */
final class StockPhotos
{
    public const SOURCES = ['pexels', 'unsplash'];

    /** Identifiants des photos de chaque banque. */
    public const IDS = ['pexels' => '/^\d{1,12}$/', 'unsplash' => '/^[A-Za-z0-9_-]{6,32}$/'];

    /** Seuls hôtes d'où une photo est téléchargée par le serveur. */
    private const HOSTS = ['pexels' => 'images.pexels.com', 'unsplash' => 'images.unsplash.com'];

    public const PER_PAGE = 30;

    private const MAX_BYTES = 15 * 1024 * 1024;

    /** Banque utilisée : celle choisie, sinon la première dont la clé est configurée ; null : aucune. */
    public static function source(): ?string
    {
        $chosen = config('services.stock_photos.source');
        $configured = array_values(array_filter(self::SOURCES, fn (string $source) => filled(config("services.{$source}.key"))));

        return in_array($chosen, $configured, true) ? $chosen : ($configured[0] ?? null);
    }

    /**
     * Une page de résultats, sous une forme commune aux deux banques.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(string $query, int $page = 1): array
    {
        $source = self::source();
        if (! $source) {
            return [];
        }
        $query = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? ''));
        $key = "stock-photos:{$source}:".sha1("{$query}|{$page}");

        return Cache::remember($key, now()->addHours(12), fn () => $source === 'pexels'
            ? array_map(self::fromPexels(...), self::get($source, 'search', ['query' => $query, 'page' => $page, 'per_page' => self::PER_PAGE, 'orientation' => 'portrait'])['photos'] ?? [])
            : array_values(array_filter(array_map(self::fromUnsplash(...), self::get($source, 'search/photos', [
                'query' => $query, 'page' => $page, 'per_page' => self::PER_PAGE, 'orientation' => 'portrait', 'content_filter' => 'high',
            ])['results'] ?? []))));
    }

    /**
     * Télécharge une photo de la banque (choisie par son identifiant, jamais par une adresse envoyée
     * par le navigateur) dans un fichier temporaire, prêt pour DesignImage::store.
     *
     * @return array{file: UploadedFile, credit: array<string, string>}
     */
    public static function download(string $source, string $id): array
    {
        abort_unless($source === self::source() && preg_match(self::IDS[$source], $id), 404);

        $photo = Cache::remember("stock-photo:{$source}:{$id}", now()->addHours(12), fn () => $source === 'pexels'
            ? self::fromPexels(self::get($source, "photos/{$id}"))
            : self::fromUnsplash(self::get($source, "photos/{$id}")));
        abort_unless(is_array($photo) && self::trusted($source, $photo['full']), 404);

        $response = self::fetch($photo['full']);
        if ($source === 'unsplash' && parse_url((string) ($photo['download'] ?? ''), PHP_URL_HOST) === 'api.unsplash.com') {
            // Exigé par Unsplash à chaque utilisation d'une photo (compteur de téléchargements de l'auteur).
            rescue(fn () => self::client($source)->get($photo['download']), report: false);
        }

        $path = tempnam(sys_get_temp_dir(), 'stock');
        file_put_contents($path, $response->body());
        $mime = (string) (getimagesize($path)['mime'] ?? '');
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            @unlink($path);
            throw ValidationException::withMessages(['id' => __('studio.stock_unavailable')]);
        }

        return [
            'file' => new UploadedFile($path, 'stock.'.($mime === 'image/png' ? 'png' : 'jpg'), $mime, null, true),
            'credit' => ['source' => $source, 'author' => $photo['author'], 'author_url' => $photo['author_url'], 'url' => $photo['url']],
        ];
    }

    /** @return array<string, mixed> */
    private static function get(string $source, string $path, array $query = []): array
    {
        try {
            $response = self::client($source)->get($path, $query);
        } catch (Throwable) {
            throw ValidationException::withMessages(['q' => __('studio.stock_unavailable')]);
        }
        if ($response->notFound()) {
            abort(404);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['q' => __('studio.stock_unavailable')]);
        }

        return (array) $response->json();
    }

    private static function client(string $source): PendingRequest
    {
        $key = (string) config("services.{$source}.key");
        $request = Http::acceptJson()->timeout(10)->connectTimeout(5)->withoutRedirecting();

        return $source === 'pexels'
            ? $request->baseUrl('https://api.pexels.com/v1')->withHeaders(['Authorization' => $key])
            : $request->baseUrl('https://api.unsplash.com')->withHeaders(['Authorization' => "Client-ID {$key}", 'Accept-Version' => 'v1']);
    }

    /** Image d'un hôte de la banque, sans redirection, 15 Mo au plus. */
    private static function fetch(string $url): Response
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)->withoutRedirecting()->get($url);
        } catch (Throwable) {
            throw ValidationException::withMessages(['id' => __('studio.stock_unavailable')]);
        }
        if (! $response->successful() || strlen($response->body()) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['id' => __('studio.stock_unavailable')]);
        }

        return $response;
    }

    private static function trusted(string $source, mixed $url): bool
    {
        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === self::HOSTS[$source];
    }

    /** @return array<string, mixed> */
    private static function fromPexels(array $photo): array
    {
        $original = (string) ($photo['src']['original'] ?? '');
        $sized = fn (int $width) => $original.(str_contains($original, '?') ? '&' : '?')."auto=compress&cs=tinysrgb&w={$width}";

        return [
            'source' => 'pexels',
            'id' => (string) ($photo['id'] ?? ''),
            'width' => (int) ($photo['width'] ?? 0),
            'height' => (int) ($photo['height'] ?? 0),
            'color' => (string) ($photo['avg_color'] ?? ''),
            'alt' => (string) ($photo['alt'] ?? ''),
            'thumb' => $sized(320),
            'preview' => $sized(1080),
            'full' => $sized(2000),
            'author' => (string) ($photo['photographer'] ?? ''),
            'author_url' => (string) ($photo['photographer_url'] ?? ''),
            'url' => (string) ($photo['url'] ?? ''),
        ];
    }

    /** @return array<string, mixed>|null null : photo payante (Unsplash+), absente de la licence libre */
    private static function fromUnsplash(array $photo): ?array
    {
        $raw = (string) ($photo['urls']['raw'] ?? '');
        if (($photo['premium'] ?? false) || ! self::trusted('unsplash', $raw)) {
            return null;
        }
        $sized = fn (int $width) => $raw.(str_contains($raw, '?') ? '&' : '?')."fm=jpg&q=80&fit=max&w={$width}";
        $referral = '?utm_source=kassevent&utm_medium=referral';

        return [
            'source' => 'unsplash',
            'id' => (string) ($photo['id'] ?? ''),
            'width' => (int) ($photo['width'] ?? 0),
            'height' => (int) ($photo['height'] ?? 0),
            'color' => (string) ($photo['color'] ?? ''),
            'alt' => (string) ($photo['alt_description'] ?? ''),
            'thumb' => $sized(320),
            'preview' => $sized(1080),
            'full' => $sized(2000),
            'download' => (string) ($photo['links']['download_location'] ?? ''),
            'author' => (string) ($photo['user']['name'] ?? ''),
            'author_url' => ((string) ($photo['user']['links']['html'] ?? '')).$referral,
            'url' => ((string) ($photo['links']['html'] ?? '')).$referral,
        ];
    }
}
