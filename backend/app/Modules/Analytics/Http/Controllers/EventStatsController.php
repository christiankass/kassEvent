<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Support\EventStatistics;
use App\Modules\Analytics\Support\Referral;
use App\Modules\Events\Models\Event;
use App\Modules\Payouts\Models\LedgerEntry;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Statistiques d'un événement et export comptable (reports.view, double authentification pour les
 * rôles argent). Événement d'une autre organisation : 404.
 */
final class EventStatsController
{
    public function show(string $id): JsonResponse
    {
        $event = Event::with('ticketTypes')->findOrFail($id);
        $url = config('app.frontend_url').'/e/'.$event->slug;

        return response()->json(['data' => [
            ...(new EventStatistics($event))->toArray(),
            'published' => $event->isPublished(),
            'url' => $url,
            // Affiche imprimée, flyer : les entrées par ce QR code sont comptées à part.
            'qr_svg' => QrCode::svg($url.'?src=qr'),
            'sources_available' => Referral::SOURCES,
        ]]);
    }

    /**
     * Export comptable : une ligne par commande payée de la page publique, avec commission et montant
     * net. Séparateurs à la française (« ; » et virgule décimale) quand la langue est le français,
     * pour l'ouvrir tel quel dans Excel.
     */
    public function export(string $id): StreamedResponse
    {
        $event = Event::findOrFail($id);
        $french = app()->getLocale() === 'fr';
        $separator = $french ? ';' : ',';
        $decimals = $event->currency === 'CDF' ? 0 : 2;
        $amount = fn (int $minor) => number_format($minor / (10 ** $decimals), $decimals, $french ? ',' : '.', '');

        $orders = (new EventStatistics($event))->paidSales()
            ->with(['items', 'succeededPayment'])->orderBy('paid_at')->orderBy('id');
        $filename = 'kassevent-'.Str::limit($event->slug, 60, '').'-ventes.csv';

        // Le fichier s'écrit après la fin du middleware « tenant » : on rouvre le contexte de l'organisation.
        return response()->streamDownload(fn () => app(TenantContext::class)->run($event->organization_id, function () use ($orders, $separator, $amount, $event) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8 (accents)
            fputcsv($out, array_map(fn ($key) => __("analytics.export.{$key}"), [
                'paid_at', 'reference', 'buyer', 'email', 'tickets', 'details', 'total', 'commission', 'net',
                'currency', 'method', 'operator', 'provider_reference', 'source',
            ]), $separator, escape: '');

            $orders->chunk(500, function ($chunk) use ($out, $separator, $amount, $event) {
                $commissions = LedgerEntry::where('type', LedgerEntry::COMMISSION)
                    ->whereIn('order_id', $chunk->modelKeys())->pluck('amount', 'order_id');
                foreach ($chunk as $order) {
                    $commission = -(int) ($commissions[$order->id] ?? 0);
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
                        $order->total === 0 ? __('analytics.method.free') : __('analytics.method.'.($payment->method ?? 'free')),
                        $payment?->operator ? __("analytics.operator.{$payment->operator}") : '',
                        $payment?->provider_reference ?? '',
                        __('analytics.source.'.($order->referral ?? Referral::DIRECT)),
                    ]), $separator, escape: '');
                }
            });
            fclose($out);
        }), $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
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
