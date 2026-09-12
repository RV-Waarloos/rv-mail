# rv-mail — fase 1 en 2

Doelgroepen, campagnes en opvolging bovenop MailerSend, als Laravel package.

Dit is **fase 1** (datamodel, migraties, models, enums, config) en **fase 2**
(audience-laag, scope-contract, composer, deduplicatie, preflight). Er wordt nog
niets verstuurd: het transport, de jobs en de webhookverwerking zijn fase 3 en 4.

Wat je hiermee wél al kunt: een campagne aanmaken, een doelgroep resolven,
dedupliceren, filteren op suppressie en uitschrijving, het snapshot wegschrijven
en een preflight-rapport opvragen.

---

## Installatie

```bash
composer require rvwaarloos/rv-mail
```

Het package ontdekt zichzelf via `extra.laravel.providers`.

### Schema-eigenaarschap

De mailtabellen leven in de gedeelde `rv_central` database. Net als bij `rv-core`
mag exact één applicatie ze migreren:

```dotenv
# enkel in rv-auth, de eigenaar van het centrale schema
RV_MAIL_OWNS_SCHEMA=true
```

Staat de vlag op `false`, dan worden de migraties niet geladen. Dat voorkomt dat
`club` per ongeluk schema-drift veroorzaakt.

### Overige omgevingsvariabelen

```dotenv
MAILERSEND_API_KEY=
RV_MAIL_WEBHOOK_SECRET=
RV_MAIL_FROM_ADDRESS=info@mail.rvwaarloos.be
RV_MAIL_FROM_NAME="RV Waarloos"
RV_MAIL_REPLY_TO=secretariaat@rvwaarloos.be
RV_MAIL_QUEUE=mail
RV_MAIL_BILLING_DAY=1          # dag waarop het MailerSend-venster van 30 dagen start
RV_MAIL_DRY_RUN=true           # lokaal en staging
```

---

## Wat je zelf moet aansluiten

Twee dingen, en ze zijn allebei bewust niet ingevuld.

### 1. Doelgroepen

`rv-mail` kent geen `Member`. Het werkt uitsluitend met `RecipientCandidate`.
De club-app registreert haar eigen doelgroepen:

```php
// club: app/Providers/AppServiceProvider.php
use RvWaarloos\RvMail\Audiences\AudienceRegistry;

$this->app->afterResolving(AudienceRegistry::class, function (AudienceRegistry $registry): void {
    $registry->register($this->app->make(ActiveMembersAudience::class));
    $registry->register($this->app->make(AfdelingAudience::class));
    $registry->register($this->app->make(TeamAudience::class));
});
```

Een doelgroep erft van `AbstractAudience` en levert kandidaten op:

```php
final class ActiveMembersAudience extends AbstractAudience
{
    public function key(): string
    {
        return 'active_members';
    }

    public function label(): string
    {
        return 'Alle actieve leden';
    }

    public function resolve(array $params): iterable
    {
        // "Actief lid" is hier gedefinieerd, en alleen hier. Een latere
        // verfijning (seizoensgebonden, lidgeld betaald) is een wijziging
        // in deze klasse en niet in een reeks query's door de codebase.
        return Member::query()
            ->where('status', MemberStatus::Active)
            ->lazyById()
            ->map(fn (Member $member): RecipientCandidate => new RecipientCandidate(
                email: (string) $member->email,
                name: $member->full_name,
                memberId: $member->id,
                personalization: [
                    'voornaam' => $member->first_name,
                    'afdeling' => $member->afdeling?->name,
                ],
                anonymized: $member->anonymized_at !== null,
            ));
    }
}
```

`AfdelingAudience` en `TeamAudience` bouwen op dezelfde basisscope voort in
plaats van de statuscontrole te herhalen.

**`AdHocAudience` krijgt bewust geen vrij tekstveld voor e-mailadressen.**
Het selecteert uit de ledendatabank. Externe bestemmelingen lopen uitsluitend via
distributielijsten, die een expliciete beheerder hebben. Dat sluit de achterdeur
waarlangs het systeem alsnog een algemene mailclient zou worden.

### 2. De scope resolver

Dit is het enige stuk autorisatie dat niet naar het aparte rollenontwerp kan.
`rv-mail.campaign.send` zegt *wat* iemand mag, niet *voor wie*.

Het package bindt nu `UnrestrictedScopeResolver`, die iedereen volledig bereik
geeft. **Dat is alleen bedoeld voor de workbench en lokale ontwikkeling.**
Vóór er in productie iets verstuurd wordt, moet de club-app dit vervangen:

```php
$this->app->bind(AudienceScopeResolver::class, ClubScopeResolver::class);
```

```php
final class ClubScopeResolver implements AudienceScopeResolver
{
    public function scopesFor(Authorizable $user): AudienceScopeSet
    {
        if ($user->can('rv-mail.send-clubwide')) {
            return AudienceScopeSet::all();
        }

        return AudienceScopeSet::of([
            'afdeling' => ['afdeling_id' => $user->verantwoordelijkeVoorAfdelingen()->pluck('id')->all()],
            'team'     => ['team_id' => $user->verantwoordelijkeVoorTeams()->pluck('id')->all()],
        ]);
    }
}
```

Of die scope uit een `verantwoordelijke`-relatie in `rv-core` komt, uit de
teams-functie van een permissiepackage, of uit iets anders — dat is precies de
vraag voor het aparte ontwerp. `rv-mail` heeft er alleen het antwoord van nodig.

---

## Autorisatie: wat het package wél en niet doet

`rv-mail` declareert permissienamen en toetst via de Gate:

```php
$user->can('rv-mail.campaign.send', $campaign);
```

Meer niet. **Geen afhankelijkheid van `spatie/laravel-permission`** in
`composer.json`: dat package haakt zelf in op de Gate, dus `can()` werkt er
vanzelf mee zodra `club` het gebruikt. De workbench kan intussen testen met een
eenvoudige `Gate::define()`. Gaat de platformdiscussie later een andere richting
uit, dan verandert er in dit package niets.

De permissies die het package verwacht:

| Permissie | Betekenis |
|---|---|
| `rv-mail.campaign.view` | Campagnes en opvolging inkijken |
| `rv-mail.campaign.create` | Mailing opstellen en testverzending doen |
| `rv-mail.campaign.send` | Definitief verzenden |
| `rv-mail.campaign.approve` | Tweede paar ogen bij een clubbrede mailing |
| `rv-mail.list.manage` | Distributielijsten beheren |
| `rv-mail.suppression.manage` | Suppressielijst corrigeren |

---

## Beslissingen die in de code zitten

Een paar dingen zijn bewust niet instelbaar gemaakt, omdat een instelling die
niemand mag aanzetten geen instelling hoort te zijn.

**Openregistratie staat uit.** `config('rv-mail.tracking.opens')` is `false` en
`MailEventType` kent `activity.opened` en `activity.opened_unique` niet eens. Een
trackingpixel is toegang tot het toestel van de ontvanger en valt onder hetzelfde
toestemmingsregime als cookies; voor een club met jeugdwerking weegt de opbrengst
daar niet tegen op. Wat je operationeel nodig hebt — bezorging, bounces, klachten —
is bezorgingsmetadata en vereist geen toestemming.

**Geen toestemmingsvlaggen.** `MailCategory` draagt de rechtsgrond:
transactioneel, operationeel en permanentie steunen op de
lidmaatschapsovereenkomst (art. 6.1.b), clubnieuws op gerechtvaardigd belang
(art. 6.1.f) met een uitschrijflink. De categorieën `Commercieel` en `Extern`
bestaan bewust níét. Zou de club ooit sponsorpromotie of mail naar oud-leden
willen sturen, dan is dat een nieuwe categorie mét opt-in-registratie en
bewijslast — geen uitbreiding van een bestaande.

**Deduplicatie staat standaard op `per_member`.** Elk gezinslid krijgt zijn eigen
gepersonaliseerde bericht, ook als drie kinderen één adres delen. Dat kost drie
credits in plaats van één; bewuste keuze. `PreflightReport::potentialSavings()`
toont het verschil zodra er gedeelde adressen in de selectie zitten, zodat het
afzetten naar `per_email` een geïnformeerde keuze is en geen verstopte instelling.

**Overgeslagen bestemmelingen blijven staan.** Wie *niet* bereikt werd is achteraf
meestal de eigenlijke vraag. Ze krijgen `status = suppressed` en een `skip_reason`
in plaats van uit het snapshot te verdwijnen.

**Het snapshot wordt gematerialiseerd bij het samenstellen**, niet pas bij
verzending — hetzelfde principe als `FixtureSnapshot`. Zo weet je achteraf exact
wie wat kreeg, ook als het lidmaatschap nadien wijzigt.

**Auditspoor via `owen-it/laravel-auditing`**, hetzelfde package als `rv-core`.
`Campaign` gebruikt de `Auditable`-trait met een expliciete `$auditInclude`. Let op
de scheiding: `mail_events` gaat over wat MailerSend met een *bericht* deed, de
audittabel over wat een *mens* met een campagne deed. Door elkaar halen levert een
log op dat niemand nog leest.

---

## Gebruik

```php
$campaign = Campaign::create([
    'name' => 'Nieuwsbrief oktober',
    'category' => MailCategory::Nieuws,
    'subject_template' => 'Clubnieuws voor {{aanspreking}}',
    'body_markdown' => $markdown,
    'from_email' => config('rv-mail.from.address'),
    'from_name' => config('rv-mail.from.name'),
    'audience_type' => 'afdeling',
    'audience_params' => ['afdeling_id' => 3],
    'dedup_strategy' => DedupStrategy::PerMember,
    'created_by' => $user->id,
]);

$result = app(CampaignComposer::class)->compose($campaign, $user);

$quota = QuotaGuard::forCurrentPeriod();

$preflight = PreflightReport::fromComposition(
    composition: $result,
    strategy: $campaign->dedup_strategy,
    quotaUsed: $quota->usedInPeriod(),
    quotaLimit: $quota->limit(),
    quotaAvailableForBulk: $quota->availableForBulk(),
    requiresApproval: $campaign->requiresApproval(),
    requiresConfirmation: $result->sendable >= config('rv-mail.limits.confirm_above_recipients'),
);
```

---

## Kwaliteit

```bash
composer test          # analyse + lint:check + type coverage + unit
composer analyse       # PHPStan level 7
composer lint          # Pint
```

Zelfde standaard als `rv-core`: `declare(strict_types=1)`, PHPStan level 7,
100% type coverage, Pest parallel.

**De tests zijn nog niet gedraaid.** Er was geen PHP beschikbaar in de omgeving
waarin dit gegenereerd is, dus reken op wat kleine correcties bij de eerste
`composer test`. De verdachte plekken staan hieronder bij de openstaande punten.

---

## Wat komt er nog

| Fase | Inhoud |
|---|---|
| 3 | `MailerSendBulkTransport`, `BatchChunker`, jobs, quotumbewaking bij verzending |
| 4 | Webhookcontroller met handtekeningverificatie, event-verwerking, suppressie-automatisering |
| 5 | Filament-resources, widgets, meldingen |
| 6 | Transactionele logging, uitschrijfpagina, `rv-mail:purge` |

---

## Openstaande punten

1. **De scope resolver moet vervangen worden** vóór productie. `UnrestrictedScopeResolver`
   geeft iedereen alles.
2. **`mailersend/mailersend-php` staat nog niet in `composer.json`.** Dat komt er in
   fase 3 bij, samen met het transport. Fase 1 en 2 versturen niets en hebben de
   SDK dus niet nodig.
3. **Unieke index op `mail_campaign_recipients`.** De combinatie
   `(campaign_id, email, member_id)` laat in MySQL meerdere NULL-`member_id`'s toe,
   wat bij `per_email` correct is maar bij een doelgroep zonder lid-koppeling tot
   duplicaten kan leiden. De composer vangt dat nu af in PHP; overweeg een
   gegenereerde kolom als je het in de database wil afdwingen.
4. **`BillingPeriod`** gaat uit van een startdag tussen 1 en 28. De echte
   MailerSend-periode start op de abonnementsdatum — die moet nog opgezocht en in
   `RV_MAIL_BILLING_DAY` gezet worden.
5. **`track_clicks`** staat er nog, per campagne en standaard uit. Gaan jullie die
   nooit gebruiken, dan kan de kolom en `MailEventType::ClickedUnique` eruit.
6. **Distributielijsten met externe adressen**: het schema laat ze toe, maar het
   beleid is "principieel geen externen". Het veld blijft beschikbaar voor
   scheidsrechters en bezoekende clubs; wie dat gebruikt neemt de
   toestemmingsverplichting op zich.
