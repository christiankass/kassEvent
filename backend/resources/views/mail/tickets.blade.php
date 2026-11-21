@php
    // Images intégrées à l'e-mail ; en aperçu (pas de message), en data URL.
    $src = fn (string $data, string $name, string $mime) => isset($message)
        ? $message->embedData($data, $name, $mime)
        : 'data:'.$mime.';base64,'.base64_encode($data);
    // Logo et bannière intégrés une seule fois, puis réutilisés sur chaque billet.
    $logoSrc = isset($images['logo']) ? $src(...$images['logo']) : null;
    $bannerSrc = isset($images['banner']) ? $src(...$images['banner']) : null;
    $colors = $look['colors'];
    $display = $design->fontStack();
    $invitation = $design->isInvitation();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="{{ $design->fontUrl() }}">
<title>{{ __($invitation ? 'ticketing.mail.subject_invitation' : 'ticketing.mail.subject', ['event' => $event->title]) }}</title>
</head>
<body style="margin:0;padding:0;background:#FBF8F3;font-family:Helvetica,Arial,sans-serif;color:#0E1B1C;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FBF8F3;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
    <tr><td style="padding:0 4px 16px;font-size:20px;font-weight:700;color:#0B3B3C;">kass<span style="color:#B87300;">Event</span></td></tr>
    <tr><td style="padding:0 4px 8px;font-size:16px;line-height:1.5;">
        {{ __('ticketing.mail.greeting', ['name' => $order->buyer_name]) }}<br>
        {{ trans_choice($invitation ? 'ticketing.mail.intro_invitation' : 'ticketing.mail.intro', $tickets->count(), ['count' => $tickets->count(), 'event' => $event->title]) }}
    </td></tr>
    <tr><td style="padding:0 4px 20px;font-size:14px;color:#5B6E6C;">
        {{ __('ticketing.mail.reference', ['reference' => $order->reference]) }}
    </td></tr>

    @foreach ($tickets as $ticket)
    <tr><td style="padding-bottom:20px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:20px;overflow:hidden;background:#FFFFFF;border:1px solid #E3DDD2;">
            @if ($bannerSrc)
            <tr><td style="padding:0;line-height:0;">
                <img src="{{ $bannerSrc }}" width="560" alt="" style="display:block;width:100%;height:auto;max-height:200px;object-fit:cover;">
            </td></tr>
            @endif
            <tr><td bgcolor="{{ $design->primary_color }}" style="background:{{ $design->primary_color }};padding:18px 20px;color:{{ $colors['on_primary'] }};">
                @if ($logoSrc)
                <img src="{{ $logoSrc }}" height="40" alt="{{ $event->organization->name }}" style="display:block;height:40px;width:auto;max-width:160px;margin-bottom:10px;border-radius:6px;">
                @endif
                <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;">
                    <span style="background:{{ $design->accent_color }};color:{{ $colors['on_accent'] }};padding:3px 8px;border-radius:9999px;">{{ $look['headline_text'] }}</span>
                    <span style="margin-left:6px;">{{ $event->organization->name }}</span>
                </div>
                <div style="font-family:{{ $display }};font-size:{{ $design->font === 'script' ? 34 : 26 }}px;font-weight:700;line-height:1.2;margin-top:8px;">{{ $event->title }}</div>
            </td></tr>
            @if ($design->message)
            <tr><td style="padding:16px 20px 0;font-size:15px;line-height:1.5;font-style:italic;color:#0E1B1C;">{!! nl2br(e($design->message)) !!}</td></tr>
            @endif
            <tr><td style="padding:16px 20px;font-size:15px;line-height:1.5;">
                <strong>{{ __('ticketing.mail.when') }}</strong> {{ $when }}<br>
                <strong>{{ __('ticketing.mail.where') }}</strong> {{ $event->venue_name }}, {{ $event->city }}<br>
                <strong>{{ __('ticketing.mail.access') }}</strong> {{ $ticket['type'] }}<br>
                <strong>{{ __($invitation ? 'ticketing.mail.guest' : 'ticketing.mail.holder') }}</strong> {{ $ticket['holder'] }}
            </td></tr>
            <tr><td bgcolor="{{ $colors['stub'] }}" style="border-top:2px dashed {{ $design->accent_color }};background:{{ $colors['stub'] }};padding:20px;" align="center">
                {{-- Le QR code reste noir sur blanc, quelle que soit l'apparence choisie : il doit se scanner partout. --}}
                <img src="{{ $src($ticket['qr'], $ticket['file'], 'image/png') }}" width="200" height="200" alt="{{ __('ticketing.mail.qr_alt') }}" style="display:block;background:#FFFFFF;border-radius:10px;padding:8px;">
                <div style="font-family:'Courier New',monospace;font-size:18px;font-weight:700;letter-spacing:.08em;margin-top:12px;color:#0E1B1C;">{{ $ticket['code'] }}</div>
            </td></tr>
            @if ($design->footer)
            <tr><td style="padding:12px 20px;font-size:12px;line-height:1.5;color:#5B6E6C;border-top:1px solid #E3DDD2;">{{ $design->footer }}</td></tr>
            @endif
        </table>
    </td></tr>
    @endforeach

    @if ($url)
    <tr><td align="center" style="padding:4px 4px 16px;">
        <a href="{{ $url }}" style="display:inline-block;background:#0B3B3C;color:#FFFFFF;text-decoration:none;font-weight:700;padding:14px 24px;border-radius:10px;">{{ __($invitation ? 'ticketing.mail.view_invitation' : 'ticketing.mail.view') }}</a>
    </td></tr>
    @endif
    <tr><td style="padding:0 4px;font-size:13px;line-height:1.5;color:#5B6E6C;">
        {{ __('ticketing.mail.keep') }}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
