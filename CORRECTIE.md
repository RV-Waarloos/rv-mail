# Correctie op fase 5: doelgroepparameters in het formulier

## Wat er mis was

Het opstelformulier liet je een groep kiezen, maar niet de parameters ervan. Je
kon "Distributielijst" selecteren zonder te zeggen wélke lijst, en "Afdeling"
zonder te zeggen welke afdeling.

`parameterSchema()` beschreef die parameters al, maar het formulier deed er
niets mee. Daardoor was de hele audience-laag onbruikbaar vanuit de interface —
alleen doelgroepen zonder parameters, zoals "alle actieve leden", werkten.

Dit is geen openstaand punt maar een defect.

## Te vervangen

| Bestand | Wijziging |
|---|---|
| `src/Contracts/Audience.php` | `parameterSchema()` uitgebreid, `describe()` toegevoegd |
| `src/Audiences/AbstractAudience.php` | Standaardimplementatie van `describe()` |
| `src/Audiences/DistributionListAudience.php` | Levert de actieve lijsten als opties |
| `src/Filament/Resources/Campaigns/Schemas/CampaignForm.php` | Bouwt de parametervelden |

**Toevoegen:** de twee testbestanden.

## Hoe het werkt

`parameterSchema()` beschrijft per parameter het type, het label, of hij
verplicht is en welke opties er zijn:

```php
    public function parameterSchema(): array
    {
        return [
            'afdeling_id' => [
                'type' => 'select',
                'label' => 'Afdeling',
                'required' => true,
                'options' => Afdeling::query()->pluck('naam', 'id')->all(),
            ],
        ];
    }
```

Het formulier bouwt daaruit zijn velden. Het kent geen enkele doelgroep, dus een
nieuwe audience in de club-app verschijnt vanzelf met de juiste velden.

Ondersteunde types: `select`, `multiselect`, `text`, `number`. Optionele
sleutels: `options`, `helper`, `default`.

## Twee details die ertoe doen

**Parameters worden gewist bij het wisselen van doelgroep.** Zonder dat
zou een afdeling-id blijven staan wanneer je naar een distributielijst
overschakelt, en dan levert het samenstellen stil nul bestemmelingen op — de
vervelendste soort fout, want er gaat niets kapot.

**`describe()` is nieuw.** "Distributielijst" zegt niets in een overzicht;
"Distributielijst: Eetfestijn 2026" wel. De standaardimplementatie zoekt de
gekozen waarden op in de opties van het schema, dus de meeste doelgroepen hoeven
er niets voor te doen.

Bestaande audiences in de club-app blijven werken: `describe()` zit in
`AbstractAudience`. Implementeer je `Audience` rechtstreeks, dan moet je hem
toevoegen.

## In de club-app

Vul `parameterSchema()` aan bij de doelgroepen die parameters hebben:

```php
final class AfdelingAudience extends AbstractAudience
{
    public function parameterSchema(): array
    {
        return [
            'afdeling_id' => [
                'type' => 'select',
                'label' => 'Afdeling',
                'required' => true,
                'options' => Afdeling::query()->orderBy('naam')->pluck('naam', 'id')->all(),
                'helper' => 'Alle actieve leden van deze afdeling.',
            ],
        ];
    }
}
```

`ActiveMembersAudience` heeft geen parameters en hoeft niets te doen.

## Nog te overwegen

Het preflight-scherm en het campagne-overzicht tonen `audience_type` als ruwe
sleutel. Nu `describe()` bestaat, kun je daar de leesbare beschrijving tonen.
Dat is een kleine wijziging in `CampaignsTable` en `CampaignInfolist`, maar ik
heb hem niet meegenomen om deze correctie beperkt te houden tot het defect.
