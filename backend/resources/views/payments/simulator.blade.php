<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('payments.simulator.title') }} · kassEvent</title>
<style>
    body { margin: 0; min-height: 100dvh; display: grid; place-items: center; background: #F3F6F5; font: 16px/1.5 system-ui, sans-serif; color: #0E1B1C; }
    main { width: min(420px, calc(100% - 32px)); background: #fff; border: 1px solid #D5DEDC; border-radius: 16px; padding: 24px; }
    .tag { display: inline-block; background: #FFF4D6; color: #2A2100; font-weight: 700; font-size: 13px; border-radius: 999px; padding: 4px 10px; }
    h1 { font-size: 22px; margin: 12px 0 4px; color: #0B3B3C; }
    .amount { font-size: 32px; font-weight: 800; margin: 8px 0 20px; font-variant-numeric: tabular-nums; }
    button { width: 100%; min-height: 48px; border-radius: 10px; font: 700 16px system-ui, sans-serif; cursor: pointer; margin-top: 8px; }
    .pay { background: #FFC83D; color: #2A2100; border: 0; }
    .decline { background: #fff; color: #C62828; border: 1px solid #D5DEDC; }
    p { color: #5B6E6C; font-size: 14px; }
</style>
</head>
<body>
<main>
    <span class="tag">{{ __('payments.simulator.tag') }}</span>
    <h1>{{ __('payments.simulator.heading') }}</h1>
    <div class="amount">{{ number_format($payment->amount / 100, $payment->currency === 'CDF' ? 0 : 2, ',', ' ') }} {{ $payment->currency === 'CDF' ? 'FC' : $payment->currency }}</div>
    <form method="post" action="{{ $action }}">
        @csrf
        <button class="pay" name="outcome" value="pay">{{ __('payments.simulator.pay') }}</button>
        <button class="decline" name="outcome" value="decline">{{ __('payments.simulator.decline') }}</button>
    </form>
    <p>{{ __('payments.simulator.note') }}</p>
</main>
</body>
</html>
