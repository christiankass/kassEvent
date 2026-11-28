@php($design = $event?->ticketDesign())
@php($colors = $design?->colors())
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
    <tr><td style="padding:0 4px 16px;font-size:15px;font-weight:700;color:#0B3B3C;">{{ $organization }}</td></tr>
    @if ($event)
    <tr><td style="background:{{ $design->primary_color }};color:{{ $colors['on_primary'] }};border-radius:20px 20px 0 0;padding:24px;">
        <div style="font-family:{{ $design->fontStack() }};font-size:26px;line-height:1.2;font-weight:700;">{{ $event->title }}</div>
        <div style="font-size:15px;margin-top:10px;">{{ $when }}</div>
        <div style="font-size:15px;">{{ $event->venue_name }}, {{ $event->city }}</div>
    </td></tr>
    @endif
    <tr><td style="background:#FFFFFF;border-radius:{{ $event ? '0 0 20px 20px' : '20px' }};padding:24px;font-size:16px;line-height:1.55;">
        @foreach ($paragraphs as $paragraph)
        <p style="margin:0 0 14px;">{!! nl2br(e($paragraph)) !!}</p>
        @endforeach
        @if ($url)
        <p style="margin:20px 0 4px;" align="center">
            <a href="{{ $url }}" style="display:inline-block;background:{{ $design->accent_color }};color:{{ $colors['on_accent'] }};text-decoration:none;font-weight:700;padding:14px 28px;border-radius:10px;">{{ $button }}</a>
        </p>
        @endif
    </td></tr>
    <tr><td style="padding:16px 8px 0;font-size:12px;line-height:1.5;color:#5B6E6C;" align="center">
        {{ __('campaigns.mail.reason', ['organization' => $organization]) }}<br>
        @if ($unsubscribe)
        <a href="{{ $unsubscribe }}" style="color:#5B6E6C;">{{ __('campaigns.mail.unsubscribe') }}</a>
        @else
        <span style="text-decoration:underline;">{{ __('campaigns.mail.unsubscribe') }}</span>
        @endif
        <br><span style="color:#8A9997;">{{ __('campaigns.mail.sent_with') }}</span>
    </td></tr>
</table>
</td></tr>
</table>
@if ($pixel)
<img src="{{ $pixel }}" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">
@endif
</body>
</html>
