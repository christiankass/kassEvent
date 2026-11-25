<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#FBF8F3;font-family:Helvetica,Arial,sans-serif;color:#0E1B1C;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FBF8F3;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
    <tr><td style="padding:0 4px 16px;font-size:20px;font-weight:700;color:#0B3B3C;">kass<span style="color:#B87300;">Event</span></td></tr>
    <tr><td style="background:{{ $design->primary_color }};color:{{ $colors['on_primary'] }};border-radius:20px 20px 0 0;padding:28px 24px;">
        <div style="font-size:14px;letter-spacing:.06em;text-transform:uppercase;opacity:.85;">{{ $event->organization->name }}</div>
        <div style="font-family:{{ $design->fontStack() }};font-size:28px;line-height:1.2;font-weight:700;margin-top:8px;">{{ $event->title }}</div>
        <div style="font-size:15px;margin-top:12px;">{{ $when }}</div>
        <div style="font-size:15px;">{{ $event->venue_name }}, {{ $event->city }}</div>
    </td></tr>
    <tr><td style="background:#FFFFFF;border-radius:0 0 20px 20px;padding:24px;font-size:16px;line-height:1.55;">
        {{ __('guests.mail.greeting', ['name' => $guest->name]) }}<br>
        {{ trans_choice('guests.mail.intro', $guest->companions_allowed, ['event' => $event->title, 'count' => $guest->companions_allowed]) }}
        @if ($design->message)
        <p style="margin:16px 0 0;color:#38504E;">{{ $design->message }}</p>
        @endif
        <p style="margin:24px 0 8px;" align="center">
            <a href="{{ $url }}" style="display:inline-block;background:{{ $design->accent_color }};color:{{ $colors['on_accent'] }};text-decoration:none;font-weight:700;padding:14px 28px;border-radius:10px;">{{ __('guests.mail.answer') }}</a>
        </p>
        <p style="margin:12px 0 0;font-size:13px;color:#5B6E6C;">{{ __('guests.mail.personal') }}</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
