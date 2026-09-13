# Wijzigingen aan RvMailServiceProvider

Vier kleine toevoegingen aan de bestaande provider. Geen volledige vervanging deze keer.

## 1. Imports erbij

```php
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use RvWaarloos\RvMail\Console\AnonymizeMemberCommand;
use RvWaarloos\RvMail\Console\PurgeCommand;
use RvWaarloos\RvMail\Listeners\LogTransactionalMail;
```

## 2. In `boot()`, naast `registerWebhookRoute()`

```php
        $this->registerWebhookRoute();
        $this->registerUnsubscribeRoutes();
        $this->registerTransactionalLogging();
```

## 3. De commando's aanvullen

```php
            $this->commands([
                QuotaCommand::class,
                ReconcileCommand::class,
                SimulateEventCommand::class,
                PurgeCommand::class,
                AnonymizeMemberCommand::class,
            ]);
```

## 4. Twee nieuwe methodes

```php
    /**
     * De uitschrijfpagina moet publiek bereikbaar zijn en de URL blijft jaren
     * geldig, dus registreer hem op de app waarvan het domein stabiel is.
     */
    private function registerUnsubscribeRoutes(): void
    {
        if (config('rv-mail.unsubscribe.register_routes') !== true) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/unsubscribe.php');
    }

    /**
     * Zonder deze listener zie je in het beheerscherm alleen de mailings, en
     * niet dat het paswoordherstel van een lid al drie keer bouncet.
     */
    private function registerTransactionalLogging(): void
    {
        if (config('rv-mail.transactional.log') !== true) {
            return;
        }

        Event::listen(MessageSent::class, LogTransactionalMail::class);
    }
```

## 5. De scheduler uitbreiden

In `registerSchedule()`, naast de bestaande reconcile:

```php
            $schedule->command('rv-mail:purge')
                ->dailyAt('03:30')
                ->withoutOverlapping();
```

## Aandachtspunt

`registerTransactionalLogging()` leest de config in `boot()`. Zet een test de
vlag ná het booten om, dan is de listener al wel of niet geregistreerd. De
listener controleert daarom zelf óók nog eens op de config — dubbel, maar het
maakt het gedrag voorspelbaar in tests.
