# rv-mail — fase 3

Transport, chunker en verzendjobs. Na deze fase gaat er effectief mail de deur uit.

## Wat waar hoort

**Nieuwe bestanden, gewoon droppen:**

```
src/Contracts/BulkTransport.php
src/Contracts/CampaignRenderer.php
src/Transport/BulkMessage.php
src/Transport/BulkBatch.php
src/Transport/BulkDispatchResult.php
src/Transport/BulkStatus.php
src/Transport/BatchChunker.php
src/Transport/MailerSendBulkTransport.php
src/Transport/FakeBulkTransport.php
src/Campaigns/RenderedCampaign.php
src/Campaigns/BladeCampaignRenderer.php
src/Campaigns/CampaignDispatcher.php
src/Jobs/SendCampaignBatch.php
src/Jobs/PollBatchStatus.php
src/Jobs/ReconcileCampaign.php
src/Console/QuotaCommand.php
src/Console/ReconcileCommand.php
src/Exceptions/CampaignNotDispatchable.php
src/Exceptions/TransportRateLimited.php
src/Exceptions/TransportRejected.php
src/Exceptions/TransportUnavailable.php
resources/views/layouts/campaign.blade.php
resources/lang/nl/mail.php
tests/**
```

**Vervangen:**

- `src/RvMailServiceProvider.php` — transport-bindings, views, vertalingen, commando's en de scheduler komen erbij

**Aanvullen:**

- `config/rv-mail.php` — één sleutel, zie `CONFIG-AANVULLING.md`

**Niets te verwijderen.** Alles uit fase 1 en 2 blijft ongewijzigd.

---

## Afwijking van het ontwerp: geen SDK

Het ontwerpdocument noemt bij D2 `mailersend/mailersend-php`. Deze fase gebruikt in plaats daarvan de Laravel HTTP client, om drie redenen.

**De 429-afhandeling heeft responseheaders nodig.** `retry-after`, `x-ratelimit-remaining` en `x-apiquota-remaining` bepalen of je over 45 seconden opnieuw mag proberen of tot morgen moet wachten. De SDK geeft een uitgepakte body terug en maakt die headers omslachtig bereikbaar. Zonder dat onderscheid moet je bij elke 429 conservatief lang wachten, wat een mailing onnodig vertraagt.

**Het gaat om drie endpoints.** `POST /v1/bulk-email`, `GET /v1/bulk-email/{id}` en `GET /v1/emails`. Daar weegt een extra dependency met eigen Guzzle-constraints niet tegenop.

**`Http::fake()` maakt de tests eerlijker.** `MailerSendTransportTest` test de echte payload en de echte headerafhandeling, in plaats van een gemockte SDK-klasse die alleen bewijst dat je je eigen mock goed geschreven hebt.

Wil je toch de SDK, dan is `MailerSendBulkTransport` de enige klasse die verandert — het contract blijft hetzelfde.

`composer.json` heeft dus geen nieuwe dependency nodig. `illuminate/support` volstaat; de HTTP client zit in de framework-installatie van de consumerende app.

---

## Hoe het werkt

### Verzenden

```php
$batches = app(CampaignDispatcher::class)->dispatch($campaign, $user);
```

De dispatcher toetst achtereenvolgens de status, de doelgroepautorisatie (opnieuw — daartussen kan een rol ingetrokken zijn), de goedkeuring en het quotum. Daarna rendert hij de campagne één keer, bouwt de berichten, chunkt ze en schrijft de batches weg.

Elke batch krijgt een absoluut verzendmoment mee:

```php
->delay(now()->addSeconds($sequence * 8))
```

Geen gedeelde rate limiter, dus geen Redis. De spreiding zit in de tijdstempels zelf en blijft correct ongeacht hoeveel workers er draaien.

### Idempotentie

Dit is het deel dat de meeste aandacht kreeg, want een dubbele clubmailing is een zichtbare fout.

`SendCampaignBatch` stopt meteen wanneer de batch al `Accepted`, `Completed` of `Failed` is. Staat hij op `InFlight` — een vorige poging ging de deur uit zonder dat we het antwoord zagen — dan vraagt de job eerst aan MailerSend of er al berichten met deze campagnetag bestaan.

Alleen bij een hard `0` wordt opnieuw verstuurd. Geeft de API geen uitsluitsel, dan blijft de batch hangen tot de reconciliatie of tot iemand kijkt. Dat is een bewuste keuze: een batch die wacht is hinderlijk, een mailing die twee keer aankomt is erger.

`ReconcileCampaign` draait elk uur. Die frequentie is niet willekeurig — op het Hobby plan bewaart MailerSend activity-data 24 uur, en daarna valt niet meer te achterhalen of een request is aangekomen.

### Foutafhandeling

| Situatie | Gedrag |
|---|---|
| 429 met `retry-after` | Batch terug op `Pending`, job released met die vertraging |
| 429 met `x-apiquota-remaining: 0` | Released tot middernacht UTC |
| 422 | Batch `Failed`, ontvangers `Failed`, geen retry |
| 5xx of netwerkfout | Batch blijft `InFlight`, job released met backoff |
| Job definitief mislukt | `failed()` zet batch en ontvangers op `Failed` |

### Statusopvolging

`PollBatchStatus` haalt na dertig seconden de eindstatus op. MailerSend verwerkt een bulk asynchroon: het 202-antwoord zegt alleen dat de request aanvaard is. Pas hier zie je welke adressen geweigerd of gesuppresseerd zijn, en die worden lokaal overgenomen zodat je ze niet bij elke mailing opnieuw probeert.

Zijn alle batches afgerond, dan gaat de campagne naar `Sent` of `Failed`.

---

## Drivers

| Driver | Wanneer |
|---|---|
| `MailerSendBulkTransport` | Productie, en tests tegen de blackhole-adressen |
| `FakeBulkTransport` | Pest, en `RV_MAIL_DRY_RUN=true` |

De fake heeft assertions ingebouwd:

```php
$transport->assertSentTo('jan@telenet.be');
$transport->assertBatchCount(3);
$transport->assertNothingSent();
$transport->failNextWith(new TransportRateLimited(retryAfter: 45));
```

Met `RV_MAIL_DRY_RUN=true` draai je lokaal de volledige pijplijn zonder credits te verbranden en zonder API-sleutel.

---

## Testen

```bash
composer test
```

Nieuw in deze fase: chunking op beide grenzen, spreiding van gedeelde adressen, de payloadopbouw (waaronder dat `track_opens` nooit aangaat), de volledige 429-, 422- en 5xx-paden, en de idempotentie van een dubbel uitgevoerde job.

---

## Openstaande punten

1. **De tests zijn niet gedraaid** — geen PHP in de omgeving waarin dit gegenereerd is. Reken op kleine correcties bij de eerste `composer test`. De verdachten staan hieronder.

2. **`$job->job = Mockery::mock(...)` in `CampaignDispatcherTest`.** `release()` vereist een onderliggende queue-job. Werkt dat niet zoals verwacht, dan is de nettere weg `Bus::fake()` of de job via `dispatch_sync()` laten lopen en op de batchstatus asserteren in plaats van op het released zijn.

3. **`chunkById()` op een `HasMany`-relatie.** In Laravel 12 werkt dat, maar de PHPStan-signatuur van de closure-parameter kan klagen. Levert dat een melding op, dan een `@param \Illuminate\Database\Eloquent\Collection<int, CampaignRecipient> $recipients` docblock toevoegen.

4. **`URL::signedRoute()` heeft een geregistreerde route nodig.** Die komt pas in fase 6. Tot dan geeft `unsubscribeUrl()` een lege string terug — de controle op `app('router')->has()` vangt dat af, maar het betekent wel dat de uitschrijflink in de mail voorlopig leeg is. Verstuur dus nog geen echte nieuwsbrief voor fase 6 er is.

5. **Rendering per batch in plaats van per campagne.** `SendCampaignBatch` rendert de campagne opnieuw bij elke batch. Dat is bewust — de job kan uren na de dispatch draaien en mag niet op een geserialiseerde HTML-blob vertrouwen — maar het kost wel wat CPU. Wordt dat merkbaar, dan is cachen op campagne-ulid met een korte TTL de volgende stap.

6. **`precedence_bulk` staat op alle mail.** Ook op transactionele. Voor paswoordherstel is dat discutabel: sommige filters behandelen bulk-gemarkeerde mail anders. Overweeg dit in fase 6 afhankelijk te maken van `MailCategory::isTransactional()`.
