<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('ticketing.mail.subject', ['event' => $event->title]) }}</title>
</head>
<body style="margin:0;padding:0;background:#F3F6F5;font-family:Helvetica,Arial,sans-serif;color:#0E1B1C;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F6F5;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
    <tr><td style="padding:0 4px 16px;font-size:20px;font-weight:700;color:#0B3B3C;">kass<span style="color:#B87300;">Event</span></td></tr>
    <tr><td style="padding:0 4px 8px;font-size:16px;line-height:1.5;">
        {{ __('ticketing.mail.greeting', ['name' => $order->buyer_name]) }}<br>
        {{ trans_choice('ticketing.mail.intro', $tickets->count(), ['count' => $tickets->count(), 'event' => $event->title]) }}
    </td></tr>
    <tr><td style="padding:0 4px 20px;font-size:14px;color:#5B6E6C;">
        {{ __('ticketing.mail.reference', ['reference' => $order->reference]) }}
    </td></tr>

    @foreach ($tickets as $ticket)
    <tr><td style="padding-bottom:20px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:20px;overflow:hidden;background:#FFFFFF;border:1px solid #D5DEDC;">
            <tr><td style="background:#0B3B3C;padding:18px 20px;color:#FFFFFF;">
                <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#FFC83D;font-weight:700;">{{ $event->organization->name }}</div>
                <div style="font-family:Georgia,serif;font-size:24px;font-weight:700;line-height:1.2;margin-top:4px;">{{ $event->title }}</div>
            </td></tr>
            <tr><td style="padding:16px 20px;font-size:15px;line-height:1.5;">
                <strong>{{ __('ticketing.mail.when') }}</strong> {{ $when }}<br>
                <strong>{{ __('ticketing.mail.where') }}</strong> {{ $event->venue_name }}, {{ $event->city }}<br>
                <strong>{{ __('ticketing.mail.access') }}</strong> {{ $ticket['type'] }}<br>
                <strong>{{ __('ticketing.mail.holder') }}</strong> {{ $ticket['holder'] }}
            </td></tr>
            <tr><td style="border-top:2px dashed #D5DEDC;background:#FFF4D6;padding:20px;" align="center">
                <img src="{{ $message->embedData($ticket['qr'], $ticket['file'], 'image/png') }}" width="200" height="200" alt="{{ __('ticketing.mail.qr_alt') }}" style="display:block;background:#FFFFFF;border-radius:10px;">
                <div style="font-family:'Courier New',monospace;font-size:18px;font-weight:700;letter-spacing:.08em;margin-top:12px;">{{ $ticket['code'] }}</div>
            </td></tr>
        </table>
    </td></tr>
    @endforeach

    <tr><td align="center" style="padding:4px 4px 16px;">
        <a href="{{ $url }}" style="display:inline-block;background:#0B3B3C;color:#FFFFFF;text-decoration:none;font-weight:700;padding:14px 24px;border-radius:10px;">{{ __('ticketing.mail.view') }}</a>
    </td></tr>
    <tr><td style="padding:0 4px;font-size:13px;line-height:1.5;color:#5B6E6C;">
        {{ __('ticketing.mail.keep') }}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
