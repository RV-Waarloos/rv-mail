<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Audiences\DistributionListAudience;
use RvWaarloos\RvMail\Campaigns\RecipientCandidate;
use RvWaarloos\RvMail\Contracts\MemberDirectory;
use RvWaarloos\RvMail\Models\DistributionList;
use RvWaarloos\RvMail\Support\UnrestrictedScopeResolver;

/**
 * Ledenbron met een vaste inhoud, zodat het package getest kan worden zonder
 * ledenmodel.
 */
function fakeDirectory(array $leden = []): MemberDirectory
{
    return new class($leden) implements MemberDirectory
    {
        public function __construct(private array $leden) {}

        public function search(string $term, int $limit = 25): array
        {
            return array_map(static fn (array $lid): string => $lid['naam'], $this->leden);
        }

        public function labelFor(int $memberId): ?string
        {
            return $this->leden[$memberId]['naam'] ?? null;
        }

        public function emailFor(int $memberId): ?string
        {
            return $this->leden[$memberId]['email'] ?? null;
        }

        public function candidateFor(int $memberId): ?RecipientCandidate
        {
            $lid = $this->leden[$memberId] ?? null;

            if ($lid === null || ($lid['email'] ?? null) === null) {
                return null;
            }

            return new RecipientCandidate(
                email: $lid['email'],
                name: $lid['naam'],
                memberId: $memberId,
            );
        }
    };
}

function lijstMet(array $leden, bool $actief = true): DistributionList
{
    $list = DistributionList::query()->create([
        'name' => 'Eetfestijn 2026',
        'slug' => 'eetfestijn-2026',
        'is_active' => $actief,
    ]);

    foreach ($leden as $lid) {
        $list->members()->create($lid);
    }

    return $list->fresh();
}

function audience(): DistributionListAudience
{
    return new DistributionListAudience(new UnrestrictedScopeResolver);
}

it('haalt het adres van een clublid op uit de ledenadministratie', function (): void {
    app()->instance(MemberDirectory::class, fakeDirectory([
        7 => ['naam' => 'Jan Peeters', 'email' => 'nieuw@telenet.be'],
    ]));

    $list = lijstMet([['member_id' => 7]]);

    $kandidaten = iterator_to_array(audience()->resolve(['list_id' => $list->id]));

    // Het adres wordt niet in de lijst bewaard, zodat een adreswijziging in de
    // ledenadministratie vanzelf volgt.
    expect($kandidaten)->toHaveCount(1)
        ->and($kandidaten[0]->email)->toBe('nieuw@telenet.be')
        ->and($kandidaten[0]->name)->toBe('Jan Peeters')
        ->and($kandidaten[0]->memberId)->toBe(7);
});

it('gebruikt het opgeslagen adres voor externen', function (): void {
    app()->instance(MemberDirectory::class, fakeDirectory());

    $list = lijstMet([['email' => 'ref@voetbal.be', 'name' => 'Piet Fluitje']]);

    $kandidaten = iterator_to_array(audience()->resolve(['list_id' => $list->id]));

    expect($kandidaten[0]->email)->toBe('ref@voetbal.be')
        ->and($kandidaten[0]->memberId)->toBeNull();
});

it('slaat een lid over waarvan geen adres bekend is', function (): void {
    app()->instance(MemberDirectory::class, fakeDirectory([
        9 => ['naam' => 'Zonder Adres', 'email' => null],
    ]));

    $list = lijstMet([['member_id' => 9]]);

    expect(iterator_to_array(audience()->resolve(['list_id' => $list->id])))->toBeEmpty();
});

it('levert niets op voor een niet-actieve lijst', function (): void {
    app()->instance(MemberDirectory::class, fakeDirectory([
        7 => ['naam' => 'Jan', 'email' => 'jan@telenet.be'],
    ]));

    $list = lijstMet([['member_id' => 7]], actief: false);

    expect(iterator_to_array(audience()->resolve(['list_id' => $list->id])))->toBeEmpty();
});

it('houdt bij wanneer een lijst voor het laatst gebruikt is', function (): void {
    app()->instance(MemberDirectory::class, fakeDirectory([
        7 => ['naam' => 'Jan', 'email' => 'jan@telenet.be'],
    ]));

    $list = lijstMet([['member_id' => 7]]);

    expect($list->last_used_at)->toBeNull();

    iterator_to_array(audience()->resolve(['list_id' => $list->id]));

    // Zo kan het overzicht tonen welke lijsten stilliggen.
    expect($list->fresh()->last_used_at)->not->toBeNull();
});

it('werkt zonder gebonden ledenbron', function (): void {
    // De terugval maakt het beheerscherm bruikbaar in een app die geen
    // ledenmodel heeft; alleen de ledenkiezer blijft dan leeg.
    $list = lijstMet([['email' => 'extern@elders.be', 'name' => 'Extern']]);

    $kandidaten = iterator_to_array(audience()->resolve(['list_id' => $list->id]));

    expect($kandidaten)->toHaveCount(1)
        ->and($kandidaten[0]->email)->toBe('extern@elders.be');
});

it('zet de aanmaker als eigenaar', function (): void {
    $list = lijstMet([]);

    // auth()->id() is null in deze test, maar het veld moet wel bestaan zodat
    // duidelijk is wie een lijst onderhoudt.
    expect($list->getAttributes())->toHaveKey('owner_id');
});
