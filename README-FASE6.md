# rv-mail — fase 6

Uitschrijfpagina, transactionele logging, opruiming en anonimisering.

Naar voren gehaald omdat de uitschrijflink het enige is dat clubnieuws tegenhoudt: `unsubscribeUrl()` gaf tot nu een lege string terug omdat de route niet bestond. Na deze fase kun je effectief een nieuwsbrief versturen.

## Wat waar hoort

**Nieuw:**

```
routes/unsubscribe.php
src/Http/Controllers/UnsubscribeController.php
src/Mail/UnsubscribeLinkMail.php
src/Listeners/LogTransactionalMail.php
src/Support/RecipientAnonymizer.php
src/Console/PurgeCommand.php
src/Console/AnonymizeMemberCommand.php
resources/views/unsubscribe/show.blade.php
resources/views/unsubscribe/request.blade.php
resources/views/mail/unsubscribe-link.blade.php
resources/lang/nl/unsubscribe.php
tests/**
```

**Vervangen:** `resources/lang/nl/mail.php` — de bestaande sleutels blijven, er komen er vijf bij

**Aanpassen:** `src/RvMailServiceProvider.php` — zie `PROVIDER-WIJZIGINGEN.md`, vier kleine toevoegingen

**Aanvullen:** `config/rv-mail.php` — zie `CONFIG-AANVULLING.md`

---

## De uitschrijfpagina

Twee eigenschappen die het ontwerp sturen.

**Geen vervaldatum.** `URL::signedRoute()` zonder expiratie. Een lid dat een mail van twee jaar geleden opendiept en op een vervallen link botst, heeft niet de eenvoudige procedure die vereist is. Er staat een test op die twee jaar vooruitreist.

**Geen login.** De routes staan buiten de `web`-middleware: geen sessie, geen CSRF-token. De ondertekende URL is de authenticatie. Een uitschrijfpagina die eerst een sessie nodig heeft is precies de omweg die je wil vermijden.

Het formulier toont alleen de categorieën die uitschrijfbaar zijn — in de praktijk dus alleen clubnieuws. Operationele mail, permanentie en transactioneel komen er niet in voor, en er staat een test op dat ze ook niet als verborgen veld opduiken.

### De uitleg onderaan

```
Praktische berichten over je lidmaatschap blijf je ontvangen:
wedstrijdwijzigingen, permanentiebeurten en lidgeld.
```

Dat is geen juridische disclaimer maar functionele noodzaak. Zonder die zin denkt een lid dat hij alles heeft afgezet, en mist hij straks een wedstrijdwijziging omdat hij de mail ongelezen weggooit.

### Opnieuw inschrijven mag

Het bezwaarrecht is absoluut, maar geen eenrichtingsverkeer. Wie zich bedenkt, vinkt het vakje terug aan en hoeft niemand te bellen.

### Het vangnet

`GET /uitschrijven` zonder handtekening toont een formulier waar iemand zijn adres invult en een verse link krijgt. Voor het geval een mailclient de URL verminkt of het signing secret ooit vervangen wordt.

De bevestiging is altijd dezelfde, of het adres nu bekend is of niet. Of iemand lid is van de club, is informatie die een willekeurige bezoeker niet hoeft te kunnen aftoetsen. Er staat een throttle van zes pogingen per minuut op.

---

## Transactionele logging

`LogTransactionalMail` luistert op `MessageSent` en registreert elke mail die buiten de campagnepijplijn om verstuurd wordt: paswoordherstel, accountbevestiging, de matchnotificaties uit het bestaande observer-systeem.

Die belanden onder een **pseudo-campagne per maand**, niet per bericht. Een campagne aanmaken per paswoordherstel zou het beheerscherm juist onbruikbaar maken.

Het quotum boekt ze apart via `emails_transactional`, zodat je ziet hoeveel van de 5.000 naar functionele mail gaat en hoeveel naar mailings.

Het nut: zonder deze listener zie je in het beheerscherm alleen de mailings, en niet dat het paswoordherstel van een lid al drie keer bouncet. Eén overzicht was het hele punt.

Uit te zetten met `RV_MAIL_LOG_TRANSACTIONAL=false`.

---

## Opruiming

```bash
php artisan rv-mail:purge --dry-run
php artisan rv-mail:purge
```

| Gegeven | Termijn |
|---|---|
| `mail_webhook_deliveries` | 30 dagen |
| `mail_events` | 396 dagen (een seizoen plus marge) |
| `mail_quota_ledger` | 730 dagen |
| `mail_campaigns` | onbeperkt |

Verwijderen gebeurt in stukken van duizend. Een enkele `DELETE` over dertien maanden events kan een grote tabel lang blokkeren, en dit draait op een gedeelde database.

Draait dagelijks om 03:30 via de scheduler.

---

## Anonimisering

```php
app(RecipientAnonymizer::class)->forMember($member->id);
```

Aan te roepen vanuit de `anonymize()`-methode van `Member` in `rv-core`. Er is ook een commando (`rv-mail:anonymize-member`) voor losse gevallen.

De rijen blijven bestaan; wat verdwijnt is de herleidbaarheid. Het adres wordt vervangen door `geanonimiseerd+<hash>@invalid`.

Die hash is bewust geen lege string: twee rijen van hetzelfde oude adres blijven als hetzelfde herkenbaar, wat de statistiek van een campagne intact houdt, zonder dat het adres te achterhalen is. Er staat een test op.

Suppressies en uitschrijvingen van het lid worden wél verwijderd — zonder adres hebben ze geen functie meer, en ze bewaren zou het doel voorbijschieten.

---

## Openstaande punten

1. **De tests zijn niet gedraaid.** Verdachten hieronder.

2. **`LogTransactionalMail::messageId()`.** Ik kon niet verifiëren hoe MailerSend het bericht-id teruggeeft via de Laravel mailer. De methode probeert drie wegen en valt terug op het Symfony-id. Klopt die niet, dan verliest de webhookverwerking de directe koppeling — maar de fallback op e-mailadres vangt dat meestal op. Controleer dit tegen een echte verzending.

3. **`$this->travel(2)->years()`** vereist `Illuminate\Foundation\Testing\Concerns\InteractsWithTime`, wat via Testbench beschikbaar hoort te zijn. Zo niet: `Carbon::setTestNow()`.

4. **De POST naar dezelfde signed URL.** Laravel's `signed`-middleware valideert de query string, dus posten naar `$request->fullUrl()` hoort te werken. Faalt dat, dan is de uitweg een verborgen veld met de handtekening en een eigen controle.

5. **`assertDontSee` met `escape: false`** in de categorieëntest is streng: hij controleert de gerenderde HTML op `value="operationeel"`. Wijzigt de view, dan moet die test mee.

6. **Nog geen meldingen bij hoge bounce-percentages.** Die horen bij fase 5, samen met de Filament-interface.

---

Na deze fase ontbreekt alleen nog fase 5: de backoffice-interface. Alles eronder werkt dan, ook zonder scherm — een campagne aanmaken kan via tinker of een seeder.
