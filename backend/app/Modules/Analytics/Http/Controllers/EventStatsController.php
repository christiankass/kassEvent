<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Support\EventStatistics;
use App\Modules\Analytics\Support\Referral;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\Money;
use App\Modules\Ticketing\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Statistiques d'un événement et export comptable (reports.view, double authentification pour les
 * rôles argent). Événement d'une autre organisation : 404.
 */
final class EventStatsController
{
    public function show(string $id): JsonResponse
    {
        $event = Event::with('ticketTypes')->findOrFail($id);
        $url = $event->publicUrl();

        return response()->json(['data' => [
            ...(new EventStatistics($event))->toArray(),
            'published' => $event->isPublished(),
            'url' => $url,
            // Affiche imprimée, flyer : les entrées par ce QR code sont comptées à part.
            'qr_svg' => QrCode::svg($url.'?src=qr'),
        ]]);
    }

    /**
     * Export comptable : une ligne par commande payée de la page publique, avec commission et montant
     * net. Séparateurs à la française (« ; » et virgule décimale) quand la langue est le français,
     * pour l'ouvrir tel quel dans Excel.
     */
    public function export(string $id): Response
    {
        $event = Event::findOrFail($id);
        $french = app()->getLocale() === 'fr';
        $separator = $french ? ';' : ',';
        $decimals = Money::allowsCents($event->currency) ? 2 : 0;
        $amount = fn (int $minor) => number_format($minor / 100, $decimals, $french ? ',' : '.', '');

        // Écrit pendant la requête (contexte de l'organisation ouvert) : quelques milliers de lignes au plus.
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8 (accents)
        fputcsv($out, array_map(fn ($key) => __("analytics.export.{$key}"), [
            'paid_at', 'reference', 'buyer', 'email', 'tickets', 'details', 'total', 'commission', 'net',
            'currency', 'method', 'operator', 'provider_reference', 'source',
        ]), $separator, escape: '');

        (new EventStatistics($event))->paidSales()->with(['items', 'succeededPayment'])
            ->orderBy('paid_at')->orderBy('id')
            ->chunk(500, function ($chunk) use ($out, $separator, $amount, $event) {
                $commissions = EventStatistics::commissions($chunk->modelKeys());
                foreach ($chunk as $order) {
                    $commission = $commissions[$order->id] ?? 0;
                    $payment = $order->succeededPayment;
                    fputcsv($out, array_map(self::cell(...), [
                        $order->paid_at?->setTimezone($event->timezone)->format('Y-m-d H:i'),
                        $order->reference,
                        $order->buyer_name,
                        $order->buyer_email,
                        (string) $order->items->sum('quantity'),
                        $order->items->map(fn ($item) => "{$item->quantity} × {$item->name}")->implode(', '),
                        $amount($order->total),
                        $amount($commission),
                        $amount($order->total - $commission),
                        $order->currency,
                        __('analytics.method.'.($order->total === 0 ? 'free' : ($payment->method ?? 'free'))),
                        $payment?->operator ? __("payouts.operators.{$payment->operator}") : '',
                        $payment?->provider_reference ?? '',
                        __('analytics.source.'.($order->referral ?? Referral::DIRECT)),
                    ]), $separator, escape: '');
                }
            });
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $filename = 'kassevent-'.Str::limit($event->slug, 60, '').'-ventes.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Cellule sans formule : un nom d'acheteur qui commence par « = », « + », « - » ou « @ » serait
     * exécuté par le tableur. On le préfixe d'une apostrophe.
     */
    private static function cell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
