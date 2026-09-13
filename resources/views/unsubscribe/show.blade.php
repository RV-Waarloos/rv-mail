<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@lang('rv-mail::unsubscribe.title')</title>
    <style>
        body { margin:0; background:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#27272a; }
        .wrap { max-width:520px; margin:48px auto; padding:0 16px; }
        .card { background:#fff; border-radius:10px; padding:32px; }
        h1 { font-size:20px; margin:0 0 8px; }
        p { line-height:1.6; font-size:15px; }
        .muted { color:#71717a; font-size:13px; }
        label { display:flex; gap:10px; align-items:flex-start; padding:14px 0; border-bottom:1px solid #f4f4f5; }
        label:last-of-type { border-bottom:0; }
        .cat { font-weight:600; font-size:15px; }
        button { margin-top:20px; background:#18181b; color:#fff; border:0; border-radius:6px; padding:11px 20px; font-size:15px; cursor:pointer; }
        .ok { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:14px; margin-bottom:20px; font-size:14px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        @if ($confirmed)
            <div class="ok">@lang('rv-mail::unsubscribe.saved')</div>
        @endif

        <h1>@lang('rv-mail::unsubscribe.title')</h1>

        @if ($email === null)
            <p>@lang('rv-mail::unsubscribe.unknown_link')</p>
            <p class="muted">
                <a href="{{ route('rv-mail.unsubscribe.request') }}">@lang('rv-mail::unsubscribe.request_new')</a>
            </p>
        @else
            <p>
                @lang('rv-mail::unsubscribe.intro', ['email' => $email])
            </p>

            <form method="POST" action="{{ $actionUrl }}">
                @foreach ($categories as $category)
                    <label>
                        <input type="checkbox"
                               name="categories[]"
                               value="{{ $category->value }}"
                               @checked(! in_array($category->value, $current, true))>
                        <span>
                            <span class="cat">{{ $category->label() }}</span><br>
                            <span class="muted">{{ $category->description() }}</span>
                        </span>
                    </label>
                @endforeach

                <button type="submit">@lang('rv-mail::unsubscribe.save')</button>
            </form>

            <p class="muted" style="margin-top:24px;">
                @lang('rv-mail::unsubscribe.operational_note')
            </p>
        @endif
    </div>
</div>
</body>
</html>
