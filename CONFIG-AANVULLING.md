# Aanvulling op config/rv-mail.php

Fase 4 voegt een `webhook`-blok toe. Plaats het onder het `mailersend`-blok:

```php
    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | Alleen de app die de webhook host zet `register_route` op true. In de
    | andere apps zou de route een tweede publiek endpoint openen waar
    | MailerSend nooit naartoe wijst.
    |
    | De ontvangende app heeft minReplicas 1 nodig: schaalt hij naar nul, dan
    | botst de eerste call van een piek op een cold start.
    |
    */

    'webhook' => [
        'register_route' => env('RV_MAIL_WEBHOOK_ROUTE', false),
        'path' => env('RV_MAIL_WEBHOOK_PATH', 'webhooks/mailersend'),
    ],
```

En in de `.env` van de app die hem host, logischerwijs `rv-auth`:

```dotenv
RV_MAIL_WEBHOOK_ROUTE=true
RV_MAIL_WEBHOOK_SECRET=   # uit het MailerSend-dashboard
```

In `tests/TestCase.php` hoeft niets: de webhooktest zet de vlag zelf.
