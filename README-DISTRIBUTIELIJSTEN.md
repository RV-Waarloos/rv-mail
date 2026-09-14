# Distributielijsten

Beheerscherm voor groepen die het systeem niet kan afleiden.

## Het onderscheid dat het ontwerp stuurt

| Soort groep | Vorm | Waarom |
|---|---|---|
| Alle actieve leden | Doelgroep | Volgt uit `status`, blijft vanzelf actueel |
| Leden van een afdeling | Doelgroep | Volgt uit de ledenadministratie |
| Ploeg die een evenement organiseert | **Lijst** | Volgt uit niets |
| Scheidsrechters, bezoekende clubs | **Lijst** | Staan niet in de ledenadministratie |
| Bestuur | Voorlopig lijst | Tot het rollenontwerp beslist of dit een rol of een functie is |

Het risico bij lijsten is concreet: iemand maakt een lijst "Alle actieve leden",
die is zes maanden later niet meer actueel, maar hij heet nog steeds zo en er
wordt naar verstuurd. Dan mailt de club een afdeling waar zeven leden gestopt
zijn en drie nieuwe ontbreken.

Het formulier waarschuwt daarom wanneer een naam op een afleidbare groep lijkt.
Het blokkeert niet — er zijn geldige uitzonderingen — maar het zegt dat er een
doelgroep bestaat die vanzelf klopt.

## Bestanden

**Nieuw:**

```
src/Contracts/MemberDirectory.php
src/Support/NullMemberDirectory.php
src/Filament/Resources/DistributionLists/**
database/migrations/2026_09_13_000001_add_usage_tracking_to_mail_distribution_lists.php
tests/Feature/DistributionListTest.php
```

**Vervangen:**

```
src/Audiences/DistributionListAudience.php
src/Models/DistributionList.php
src/Filament/RvMailPlugin.php
```

## Service provider

```php
use RvWaarloos\RvMail\Contracts\MemberDirectory;
use RvWaarloos\RvMail\Support\NullMemberDirectory;

// in register()
$this->app->bind(MemberDirectory::class, NullMemberDirectory::class);
```

## In de club-app

Bind de echte ledenbron:

```php
$this->app->bind(MemberDirectory::class, ClubMemberDirectory::class);
```

```php
final class ClubMemberDirectory implements MemberDirectory
{
    public function search(string $term, int $limit = 25): array
    {
        return Member::query()
            ->where('status', MemberStatus::Active)
            ->where(fn ($q) => $q->where('last_name', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"))
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Member $m): array => [$m->id => $m->full_name])
            ->all();
    }

    public function labelFor(int $memberId): ?string
    {
        return Member::query()->find($memberId)?->full_name;
    }

    public function emailFor(int $memberId): ?string
    {
        return Member::query()->find($memberId)?->email;
    }
}
```

Zonder die binding werkt het scherm nog steeds, alleen blijft de ledenkiezer
leeg. Een package dat crasht omdat een optionele integratie ontbreekt, is een
package dat je niet durft te installeren.

## Adressen van clubleden worden niet opgeslagen

Een lijstlid met een `member_id` bewaart geen e-mailadres. Dat wordt bij elke
mailing opnieuw opgehaald via `MemberDirectory::emailFor()`.

Zo blijft een lijst kloppen wanneer iemand van adres verandert, zonder dat de
lijstbeheerder daar iets van hoeft te weten. Het opgeslagen adres in de tabel is
uitsluitend voor externen.

## Permissies

| Permissie | Geeft toegang tot |
|---|---|
| `rv-mail.list.manage` | Lijsten aanmaken, bewerken, clubleden toevoegen |
| `rv-mail.list.manage-external` | Het vrije e-mailadresveld |

Die tweede is nieuw. Externen mailen is de uitzondering op het uitgangspunt dat
de club alleen haar eigen leden aanschrijft, en tegelijk de achterdeur waarlangs
het systeem alsnog een algemene mailclient kan worden. Wie alleen ledenlijsten
beheert, heeft dat veld niet nodig.

Voeg hem toe aan `rv-mail:permissions` en aan het platformbrede rollenontwerp.

## Vergeten lijsten

Het overzicht markeert lijsten die zes maanden niet gebruikt zijn. Geen
automatische opruiming — een lijst voor een tweejaarlijks evenement is niet
vergeten, hij wacht. Maar hij is wel het nakijken waard voor je hem gebruikt.

`last_used_at` wordt bijgewerkt zodra de doelgroep geresolved wordt, dus ook bij
een preflight die je daarna afbreekt. Dat is bewust ruim: het gaat om "heeft
iemand hier recent naar gekeken", niet om een verzendteller.

## Openstaande punten

1. **De tests draaien tegen `UnrestrictedScopeResolver`.** In de club-app bepaalt
   de echte resolver wie welke lijst mag aanspreken. Overweeg scoping per
   eigenaar als lijsten door verschillende mensen beheerd worden.
2. **`requiredWithout('member_id')`** op het adresveld: controleer of die regel
   in Filament v5 zo heet. Anders een eigen `rule()`.
3. **De ledenkiezer toont alleen actieve leden** in het voorbeeld hierboven. Voor
   een evenementenploeg met een oud-lid erbij wil je dat misschien ruimer.
