<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@lang('rv-mail::unsubscribe.request_title')</title>
    <style>
        body { margin:0; background:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#27272a; }
        .wrap { max-width:520px; margin:48px auto; padding:0 16px; }
        .card { background:#fff; border-radius:10px; padding:32px; }
        h1 { font-size:20px; margin:0 0 8px; }
        p { line-height:1.6; font-size:15px; }
        input[type=email] { width:100%; box-sizing:border-box; padding:11px; border:1px solid #d4d4d8; border-radius:6px; font-size:15px; margin-top:12px; }
        button { margin-top:16px; background:#18181b; color:#fff; border:0; border-radius:6px; padding:11px 20px; font-size:15px; cursor:pointer; }
        .ok { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:14px; font-size:14px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>@lang('rv-mail::unsubscribe.request_title')</h1>

        @if ($sent)
            {{-- Altijd dezelfde bevestiging: of een adres bij ons bekend is,
                 hoeft een willekeurige bezoeker niet te weten. --}}
            <div class="ok">@lang('rv-mail::unsubscribe.request_sent')</div>
        @else
            <p>@lang('rv-mail::unsubscribe.request_intro')</p>

            <form method="POST" action="{{ route('rv-mail.unsubscribe.send-link') }}">
                <input type="email" name="email" required placeholder="jouw@adres.be">
                <button type="submit">@lang('rv-mail::unsubscribe.request_submit')</button>
            </form>
        @endif
    </div>
</div>
</body>
</html>
