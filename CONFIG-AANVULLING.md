# Aanvulling op config/rv-mail.php

Fase 3 voegt één sleutel toe aan de bestaande configuratie. Zoek het blok
`'mailersend' => [` en vul aan:

```php
    'mailersend' => [
        'api_key' => env('MAILERSEND_API_KEY'),
        'webhook_secret' => env('RV_MAIL_WEBHOOK_SECRET'),
        'dry_run' => env('RV_MAIL_DRY_RUN', false),
        'timeout' => (int) env('RV_MAIL_TIMEOUT', 30),
    ],
```

De rest van het configbestand blijft ongewijzigd. `batch`, `throttle`,
`quota`, `approval` en `tracking` waren al voorzien in fase 1.
