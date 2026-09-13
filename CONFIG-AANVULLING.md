# Aanvullingen op config/rv-mail.php

## Vervang het bestaande `unsubscribe`-blok

```php
    'unsubscribe' => [
        'register_routes' => env('RV_MAIL_UNSUBSCRIBE_ROUTES', false),
        'path' => env('RV_MAIL_UNSUBSCRIBE_PATH', 'uitschrijven'),
        'route' => 'rv-mail.unsubscribe',
        'fallback_route' => 'rv-mail.unsubscribe.request',
    ],
```

## Voeg toe

```php
    'transactional' => [
        // Logt mail die buiten de campagnepijplijn om verstuurd wordt, zodat
        // het beheerscherm één overzicht toont in plaats van twee.
        'log' => env('RV_MAIL_LOG_TRANSACTIONAL', true),
    ],
```

## In de .env van de publieke app

```dotenv
RV_MAIL_UNSUBSCRIBE_ROUTES=true
```

Zet dit op dezelfde app als de webhookroute. De uitschrijfpagina moet publiek
bereikbaar zijn, en de URL komt in elke nieuwsbrief te staan — kies het domein
dus bewust, want hij blijft jaren geldig.
