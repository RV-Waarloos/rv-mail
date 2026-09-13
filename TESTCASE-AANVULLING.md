# Aanvulling op tests/TestCase.php

De webhooktests doen echte HTTP-calls naar de eigen route, dus de
exception-handling moet aan blijven staan om de 401 te kunnen zien. Testbench
doet dat standaard goed; er is geen wijziging nodig.

Wel dit, als je de route in alle tests beschikbaar wil hebben in plaats van
per test:

```php
    protected function defineEnvironment($app): void
    {
        // ... bestaande regels
        $app['config']->set('rv-mail.webhook.register_route', true);
        $app['config']->set('rv-mail.mailersend.webhook_secret', 'test-signing-secret');
    }
```

Doe je dat, dan kan de `beforeEach` in `WebhookTest.php` korter. Ik heb hem
daar laten staan zodat het bestand op zichzelf leesbaar blijft.
