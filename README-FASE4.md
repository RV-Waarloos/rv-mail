# rv-mail — fase 4

Webhookontvangst, event-log en automatische suppressie. Dit sluit de lus die fase 3 openliet: ontvangers gingen naar `Sent` en daarna gebeurde er niets meer.

## Wat waar hoort

**Nieuw:**

```
routes/webhooks.php
src/Webhooks/WebhookPayload.php
src/Webhooks/SignatureVerifier.php
src/Webhooks/RecipientResolver.php
src/Http/Middleware/VerifyMailerSendSignature.php
src/Http/Controllers/MailerSendWebhookController.php
src/Jobs/ProcessWebhookEvent.php
src/Console/SimulateEventCommand.php
tests/**
```

**Vervangen:** `src/RvMailServiceProvider.php`

**Aanvullen:** `config/rv-mail.php` — zie `CONFIG-AANVULLING.md`

---

## De ontvangstroute

`POST /webhooks/mailersend`, buiten de `web`-middleware: geen sessie, geen CSRF, geen auth.

De route wordt alleen geregistreerd wanneer `RV_MAIL_WEBHOOK_ROUTE=true`. Zet dat in exact één app — `rv-auth` of de publieke site, niet de backoffice als die achter authenticatie zit. In de andere apps zou de route een tweede publiek endpoint openen waar MailerSend nooit naartoe wijst.

De controller doet vier dingen en meer niet: handtekening valideren (in de middleware), ruwe payload wegschrijven met `insertOrIgnore`, `202` teruggeven, job dispatchen.

```php
DB::table('mail_webhook_deliveries')->insertOrIgnore([...]);
```

Geen Eloquent-model bij die insert. Bij tienduizend calls per maand scheelt dat geen meetbare kosten, maar het houdt de ontvangstroute vrij van model-events, observers en casts — dingen die er later ongemerkt bij komen en de request dan trager maken op precies het moment dat het telt.

---

## Handtekeningverificatie

MailerSend ondertekent met HMAC-SHA256 over de ruwe body, hexadecimaal, in de header `Signature`.

**Cruciaal detail:** de middleware gebruikt `$request->getContent()`, niet `$request->all()`. De handtekening geldt over de bytes zoals ze binnenkwamen. Eerst decoderen en dan opnieuw encoderen levert een andere string op, want sleutelvolgorde en escaping hoeven niet bewaard te blijven. Er staat een test op die precies dat vastlegt.

Vergelijken gebeurt met `hash_equals`, niet met `===`.

Een ongeldige handtekening logt een waarschuwing zonder de payload: die kan ledengegevens bevatten en we weten niet wie hem stuurde.

---

## Idempotentie

Drie lagen, want MailerSend probeert opnieuw bij een trage of mislukte levering:

1. `insertOrIgnore` op de unieke index van `ms_event_id` — een tweede identieke levering maakt geen tweede rij
2. De controller geeft ook bij een duplicaat netjes `202`, anders blijft MailerSend het proberen
3. `ProcessWebhookEvent` stopt meteen wanneer `processed_at` al gezet is

---

## Statusvolgorde

Webhooks komen niet gegarandeerd in volgorde aan. Een `sent` die na een `delivered` binnenvalt mag de status niet terugzetten.

`ProcessWebhookEvent::outranks()` bepaalt daarom een rangorde: `pending` < `queued` < `sent` < `delivered` < `soft_bounced` < `failed` < `hard_bounced`. Alleen een hogere rang overschrijft. Een bounce wint altijd.

---

## Terugkoppeling naar de ontvanger

Drie sleutels, in volgorde van betrouwbaarheid:

| Sleutel | Waarom |
|---|---|
| `rcpt:<ulid>` uit de tags | De bedoelde weg. Custom headers zijn Professional-only. |
| `ms_message_id` | Vangnet als tags wegvallen |
| E-mailadres binnen de campagne | Laatste redmiddel, en alleen wanneer er precies één match is |

Die derde voorwaarde is niet overbodig: bij een gezin dat een adres deelt en waar elk lid zijn eigen mail kreeg, zijn er meerdere rijen met hetzelfde adres binnen dezelfde campagne. Zonder de `count() === 1`-controle zou je willekeurig een gezinslid als bouncer aanmerken.

Lukt geen van de drie, dan wordt het event tóch gelogd, met `recipient_id` op null. Anders verlies je het spoor bij mail die buiten dit package om verstuurd is.

---

## Automatische reacties

| Event | Gevolg |
|---|---|
| `hard_bounced` | Meteen in `mail_suppressions` |
| `spam_complaint` | Meteen in `mail_suppressions`, onomkeerbaar |
| `unsubscribed` | Rij in `mail_unsubscribes` voor de categorie van de campagne |

De uitschrijving is bewust categoriegebonden en géén algemene suppressie. Wie het clubnieuws afzet, moet de permanentie-oproepen blijven krijgen.

Komt er een uitschrijving binnen op een operationele campagne, dan gebeurt er niets. Dat kan niet correct zijn — operationele mail heeft geen uitschrijflink — en een ruime suppressie zou het lid onbereikbaar maken voor praktische clubzaken. Er staat een test op.

---

## Testen zonder credits

```bash
php artisan rv-mail:simulate-event --type=hard_bounced --limit=3
php artisan rv-mail:simulate-event --campaign=01JABC --type=delivered --all
```

Het commando bouwt een payload in het MailerSend-formaat, ondertekent hem correct en POST't hem naar je eigen endpoint. Bewust via HTTP en niet rechtstreeks naar de job: zo lopen de handtekeningcontrole, de idempotentie en de volledige verwerking mee. Events rechtstreeks in `mail_events` schrijven zou precies die drie overslaan, en dat zijn de plekken waar fouten stil blijven.

Draai je twee keer dezelfde simulatie, dan zie je de idempotentie werken.

Het commando weigert te draaien in productie.

---

## Openstaande punten

1. **De tests zijn niet gedraaid.** Verdachten hieronder.

2. **`test()->call(...)` in `postWebhook()`.** De ruwe body meegeven vereist de zevende parameter van `call()`. Werkt dat niet, dan is de alternatieve weg `$this->withHeaders([...])->postJson()` met een vooraf berekende handtekening over exact dezelfde JSON-string — let op dat `postJson` zelf encodeert, wat de handtekening kan breken. Dat is precies het probleem waar de middleware tegen beschermt.

3. **`const WEBHOOK_SECRET` op bestandsniveau.** Bij parallelle Pest-runs kan een dubbele declaratie optreden als een ander testbestand dezelfde naam gebruikt. Wordt dat een probleem, dan een gewone variabele in `beforeEach`.

4. **De ping-payload heeft geen `data.email`.** `WebhookPayload::fromArray()` vangt dat af met lege arrays, maar het echte formaat van `webhook.test` heb ik niet kunnen verifiëren. Controleer dat bij het opzetten van de webhook in het dashboard.

5. **Bounce-reden komt uit `morph.reason` of `morph.bounce_code`.** De exacte veldnamen per eventsoort verschillen; controleer dit tegen een echte levering en pas `WebhookPayload` aan waar nodig.

6. **Nog geen meldingen.** Het ontwerp voorziet waarschuwingen bij een bounce-percentage boven 5% en bij een ongeldige handtekening. De data zit er nu; de notificaties horen bij fase 5.
