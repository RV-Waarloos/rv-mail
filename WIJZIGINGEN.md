# Aanpassing: TestSender via BulkTransport

## Waarom

`TestSender` gebruikte `Mail::html()` en hing daarmee aan de geconfigureerde
mailer van de app. Dat betekende dat `club` een volledige mailconfiguratie nodig
had — inclusief de MailerSend-driver — terwijl `club` mailings alleen opstelt en
ze niet zelf verstuurt. De worker doet dat.

Symptoom: `Unsupported mail transport [mailersend]` bij de testknop.

Nu loopt de testverzending door hetzelfde `BulkTransport` als de campagne zelf.
Elke app die het package gebruikt heeft daarmee precies één manier om mail te
versturen.

## Te vervangen

| Bestand | Wijziging |
|---|---|
| `src/Campaigns/TestSender.php` | Volledig vervangen |
| `src/Transport/BulkBatch.php` | Volledig vervangen — `record` mag nu null zijn |
| `tests/Feature/TestSenderTest.php` | Volledig vervangen |

## Gevolg elders

`BulkBatch::$record` is nu `?CampaignBatch`. Controleer of PHPStan klaagt over
plaatsen die `$batch->record->id` aanroepen. In `SendCampaignBatch` wordt het
record niet via `BulkBatch` benaderd maar rechtstreeks, dus daar hoort niets te
veranderen.

`recipientIds()` filtert nu ids op nul weg, want een testbericht heeft er geen.
Dat voorkomt dat een 422 op een testverzending een bestaande rij zou raken.

## In club

`MAIL_MAILER` mag daar nu op `log` of `array` blijven staan. De testknop werkt
zonder mailconfiguratie, zolang `MAILERSEND_API_KEY` gezet is of
`RV_MAIL_DRY_RUN=true` staat.

Met `RV_MAIL_DRY_RUN=true` gaat er niets de deur uit en zie je de verzending in
het log — handig zolang je de opmaak aan het bijschaven bent.

---

# Aanpassing 2: FakeBulkTransport logt in dry-run

## Waarom

`RV_MAIL_DRY_RUN=true` verving het transport door de fake, maar die bewaarde
alles alleen in het geheugen. Na de request was het weg, en er kwam niets in het
log. Daarmee was de modus bruikbaar om te controleren *dát* er iets zou
vertrekken, en niet *wat* — terwijl dat laatste precies is waarvoor je hem
lokaal aanzet.

## Te vervangen

`src/Transport/FakeBulkTransport.php`

## Gedrag

| Context | Logt |
|---|---|
| `RV_MAIL_DRY_RUN=true`, gewone request | Ja |
| Testsuite | Nee |
| Expliciet via `->withLogging()` | Volgens de meegegeven waarde |

Het loggen staat uit in tests omdat honderd tests die elk een volledige
HTML-body wegschrijven het log onleesbaar maken. Wil je in een test toch het
log nakijken, dan zet `->withLogging()` het aan.

Per batch komt er een regel met het aantal en de afzender, en per bericht een
regel met de ontvanger, het onderwerp, de tags, de personalisatie en de
volledige HTML.

## Optionele config

Wil je de dry-run-uitvoer in een apart kanaal:

```php
    'mailersend' => [
        // ... bestaande sleutels
        'log_channel' => env('RV_MAIL_LOG_CHANNEL'),
    ],
```

Leeg laten gebruikt het standaardkanaal.

## In club

```dotenv
RV_MAIL_DRY_RUN=true
```

De testverzending verschijnt dan in `storage/logs/laravel.log`. Zoek op
`rv-mail dry-run`.

Wil je zien hoe Gmail de mail rendert — wat eerlijker is dan een browser of een
logbestand — zet `RV_MAIL_DRY_RUN=false` met een geldige `MAILERSEND_API_KEY`
en stuur de test naar je eigen adres. Dat kost één credit.
