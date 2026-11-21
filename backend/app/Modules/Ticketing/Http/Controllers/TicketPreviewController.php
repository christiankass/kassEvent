<?php

namespace App\Modules\Ticketing\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Ticketing\Mail\TicketsMail;
use App\Modules\Ticketing\Support\QrCode;
use App\Modules\Ticketing\Support\SampleOrder;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

/**
 * Billet d'exemple de l'atelier : QR code pour l'aperçu en direct et e-mail tel qu'il partira
 * (le site l'affiche dans un cadre isolé, sans script). Événement d'une autre organisation : 404.
 */
final class TicketPreviewController
{
    public function show(string $id): JsonResponse
    {
        $mail = new TicketsMail(SampleOrder::for(Event::with('design')->findOrFail($id), auth()->user()->name));

        return response()->json(['data' => [
            'subject' => $mail->envelope()->subject,
            'html' => $mail->render(),
            'qr' => 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg(SampleOrder::CODE)),
            'code' => TicketCode::format(SampleOrder::CODE),
        ]]);
    }

    /** Envoie l'e-mail d'exemple à l'adresse de l'utilisateur connecté, et à elle seule. */
    public function send(string $id): JsonResponse
    {
        $user = auth()->user();
        $event = Event::with('design')->findOrFail($id);

        Mail::to($user->email, $user->name)->send(new TicketsMail(SampleOrder::for($event, $user->name)));

        return response()->json(['message' => __('designs.test_sent', ['email' => $user->email])]);
    }
}
