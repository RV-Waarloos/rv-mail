<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $campaign->name }}</title>
</head>

<body
    style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                    style="max-width:600px;background-color:#ffffff;border-radius:8px;">
                    <tr>
                        <td style="padding:24px 32px;border-bottom:1px solid #e4e4e7;">
                            <strong style="font-size:18px;color:#18181b;">RV Waarloos</strong>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;color:#27272a;font-size:15px;line-height:1.6;">
                            {!! $body !!}
                        </td>
                    </tr>
                    <tr>
                        <td
                            style="padding:20px 32px;border-top:1px solid #e4e4e7;color:#71717a;font-size:12px;line-height:1.5;">
                            {{-- Elke mail legt uit waarom je hem krijgt; enkel uitschrijfbare categorieën krijgen ook een link. --}}
                            <p style="margin:0 0 8px 0;">
                                @lang('rv-mail::mail.why_you_receive')
                            </p>
                            @if ($showUnsubscribe)
                                <p style="margin:0;">
                                    <a href="@{{ unsubscribe_url }}" style="color:#71717a;">@lang('rv-mail::mail.unsubscribe_link')</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
