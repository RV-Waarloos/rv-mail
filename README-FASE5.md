# rv-mail — fase 5

De backoffice-interface: mailings opstellen, preflight, verzenden, opvolgen.

## Eerst dit

**Ik heb de Filament v5-conventies opgezocht maar niets kunnen draaien.** De code volgt de v4/v5-migratieregels die ik heb kunnen verifiëren:

| v3 | v4/v5 |
|---|---|
| `Forms\Form` en `Infolists\Infolist` | `Filament\Schemas\Schema` |
| `Forms\Components\Section` | `Filament\Schemas\Components\Section` |
| `Tables\Actions\*` | `Filament\Actions\*` |
| `->actions()` | `->recordActions()` |
| `->bulkActions()` | `->toolbarActions()` |
| Modal `->form()` | `->schema()` |
| `?string $navigationIcon` | `string\|BackedEnum\|null` |
| `Resources/XResource.php` | `Resources/X/XResource.php` met `Schemas/`, `Tables/`, `Pages/` |

Invoercomponenten (`TextInput`, `Select`, `MarkdownEditor`) blijven wél onder `Filament\Forms\Components`. Dat is de val waar migraties meestal in trappen: alles naar `Schemas` verplaatsen is net zo fout als niets verplaatsen.

Reken op aanpassingen bij de eerste keer laden. De verdachten staan onderaan.

## Installatie

```bash
composer require --dev filament/filament:"^5.0"
```

In de club-app, bij het panel:

```php
use RvWaarloos\RvMail\Filament\RvMailPlugin;

$panel->plugin(RvMailPlugin::make());
```

Of zonder de dashboardwidgets:

```php
$panel->plugin(RvMailPlugin::make()->withoutDashboardWidgets());
```

Als plugin en niet automatisch vanuit de service provider, want de club-app bepaalt zelf in welk panel de schermen horen.

**Voeg `TestSender` toe aan `src/Campaigns/`** — die zit in deze zip en hoort niet in de Filament-map, want hij is ook bruikbaar zonder interface.

## Wat je krijgt

### Mailings

Lijst met status, soort, aantal en een bouncekolom die kleurt vanaf 2% en rood wordt vanaf 5%. Zo zie je een verouderde adressenlijst zonder te rekenen.

De opsteller toont bij elke keuze wat ze betekent. Kies je een categorie, dan verschijnt de rechtsgrond eronder, en of er een uitschrijflink in komt. Kies je een dedup-strategie, dan zie je wat die doet met gezinnen.

### Het preflight-scherm

De belangrijkste knop van het hele package. Voor het verzenden krijg je:

- hoeveel bestemmelingen je bereikt
- hoeveel er worden overgeslagen, met een verwijzing naar de reden per persoon
- het quotumverbruik nu en na verzending
- of er goedkeuring nodig is
- hoeveel adressen gedeeld worden en wat samenvoegen zou besparen

Die laatste regel verschijnt alleen als er effectief gedeelde adressen zijn. De standaard blijft één mail per lid, maar de keuze is zichtbaar in plaats van verstopt.

### Detailpagina

De trechter loopt van verzonden naar afgeleverd naar bounces. **Geen openingspercentage**, want dat wordt niet gemeten.

De pagina ververst zichzelf elke 15 seconden zolang de mailing loopt, en stopt daarmee zodra de status definitief is. Anders zit de verantwoordelijke te herladen.

### Bestemmelingen

Inclusief wie is overgeslagen en waarom. Dat is meestal de eigenlijke vraag: waarom heeft Peter die mail niet gekregen?

### Geblokkeerde adressen

Deblokkeren kan, maar alleen bij een hard bounce of een handmatige blokkade. Een spamklacht is onomkeerbaar, en de knop verschijnt daar dan ook niet.

## Gedeelde acties

Alle campagneacties staan in `CampaignActions`, als losse fabrieken:

```php
CampaignActions::compose();
CampaignActions::dispatch();
CampaignActions::sendTest();
CampaignActions::approve();
CampaignActions::retryFailed();
CampaignActions::cancel();
```

Ze staan daar en niet in de resource, zodat de lijstweergave, de detailpagina en een eventuele relation manager exact hetzelfde gedrag krijgen. Diezelfde logica op drie plaatsen herhalen is hoe de knop op de ene plek wél de goedkeuring controleert en op de andere niet.

## Autorisatie

Elke resource, actie en widget toetst via de Gate:

```php
auth()->user()?->can('rv-mail.campaign.send')
```

Geen rollen in het package. De goedkeuringsactie controleert bovendien `canBeApprovedBy()`, dus wie `send` én `approve` heeft kan nog altijd niet zijn eigen clubbrede mailing goedkeuren.

**Verwijderen is overal uitgeschakeld.** Een campagne weggooien wist het snapshot, en dat is precies wat je nodig hebt als er ooit een vraag over komt.

## Openstaande punten

1. **`Filament\Schemas\Components\Text`** gebruik ik voor de preflight-regels. Bestaat die klasse niet onder die naam, dan is het alternatief `Placeholder::make('')->content(...)` uit `Filament\Forms\Components`, of een custom Blade-view in de modal.

2. **`Filament\Schemas\Components\Utilities\Get`** in `CampaignForm` — het pad van `Get` is tussen versies verschoven. Faalt de import, probeer dan `Filament\Forms\Get`.

3. **`->modalWidth('2xl')`** — v5 gebruikt mogelijk de `Width`-enum: `->modalWidth(Width::TwoExtraLarge)`.

4. **`getNavigationBadge()`** telt op stringwaarden van de enum. Werkt, maar `whereIn('status', [CampaignStatus::Dispatching, CampaignStatus::PendingApproval])` is netter als de cast dat toelaat.

5. **`canViewForRecord()` in de relation manager** — de signatuur (`$ownerRecord`, `string $pageClass`) kan in v5 getypeerd zijn. PHPStan zal het melden.

6. **De `groupBy`-query in het preflight-scherm** draait op elke opening van de modal. Bij een clubbrede mailing met 400 rijen is dat verwaarloosbaar; wordt het ooit traag, dan is het resultaat cachen op `composed_at` de volgende stap.

7. **Notificaties bij een hoog bouncepercentage** zitten er nog niet. De data is er; het is een scheduled command dat de drempel toetst en een Filament-notificatie wegschrijft. Kleine toevoeging, maar ik heb hem niet gebouwd omdat de drempelwaarden eerst vastgelegd moeten worden.

8. **Distributielijsten hebben nog geen resource.** Ze zijn beheersbaar via tinker; een eenvoudige CRUD-resource is een halfuur werk wanneer je ze effectief gaat gebruiken.
