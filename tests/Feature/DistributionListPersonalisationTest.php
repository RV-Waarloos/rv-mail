<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Audiences\DistributionListAudience;
use RvWaarloos\RvMail\Campaigns\RecipientCandidate;
use RvWaarloos\RvMail\Contracts\MemberDirectory;
use RvWaarloos\RvMail\Models\DistributionList;
use RvWaarloos\RvMail\Support\NullMemberDirectory;
use RvWaarloos\RvMail\Support\UnrestrictedScopeResolver;

function personalisatieDirectory(array $kandidaten): MemberDirectory
{
    return new class($kandidaten) implements MemberDirectory
    {
        /** @param array<int, RecipientCandidate> $kandidaten */
        public function __construct(private array $kandidaten) {}

        public function search(string $term, int $limit = 25): array
        {
            return [];
        }

        public function labelFor(int $memberId): ?string
        {
            return $this->kandidaten[$memberId]->name ?? null;
        }

        public function emailFor(int $memberId): ?string
        {
            return $this->kandidaten[$memberId]->email ?? null;
        }

        public function candidateFor(int $memberId): ?RecipientCandidate
        {
            return $this->kandidaten[$memberId] ?? null;
        }
    };
}

function personalisatieLijst(array $leden): DistributionList
{
    $list = DistributionList::query()->create([
        'name' => 'Eetfestijn 2026',
        'slug' => 'eetfestijn-2026',
        'is_active' => true,
    ]);

    foreach ($leden as $lid) {
        $list->members()->create($lid);
    }

    return $list->fresh();
}

function personalisatieAudience(): DistributionListAudience
{
    return new DistributionListAudience(new UnrestrictedScopeResolver);
}

it('geeft een clublid dezelfde personalisatie als een ledendoelgroep', function (): void {
    app()->instance(MemberDirectory::class, personalisatieDirectory([
        4 => new RecipientCandidate(
            email: 'sofie@telenet.be',
            name: 'Sofie Claes',
            memberId: 4,
            personalization: [
                'voornaam' => 'Sofie',
                'achternaam' => 'Claes',
                'aanspreking' => 'Sofie',
                'afdeling' => 'Dames',
            ],
        ),
    ]));

    $list = personalisatieLijst([['member_id' => 4]]);

    $result = iterator_to_array(personalisatieAudience()->resolve(['list_id' => $list->id]), false);

    // Anders wordt dezelfde persoon in de ene mailing met naam aangesproken en
    // in de andere niet, afhankelijk van hoe de opsteller hem selecteerde.
    expect($result[0]->personalization['voornaam'])->toBe('Sofie')
        ->and($result[0]->personalization['achternaam'])->toBe('Claes')
        ->and($result[0]->personalization['afdeling'])->toBe('Dames');
});

it('voegt de lijstnaam toe zonder de rest te overschrijven', function (): void {
    app()->instance(MemberDirectory::class, personalisatieDirectory([
        4 => new RecipientCandidate(
            email: 'sofie@telenet.be',
            name: 'Sofie Claes',
            memberId: 4,
            personalization: ['voornaam' => 'Sofie'],
        ),
    ]));

    $list = personalisatieLijst([['member_id' => 4]]);

    $result = iterator_to_array(personalisatieAudience()->resolve(['list_id' => $list->id]), false);

    expect($result[0]->personalization['lijst'])->toBe('Eetfestijn 2026')
        ->and($result[0]->personalization['voornaam'])->toBe('Sofie');
});

it('splitst de naam van een externe bestemmeling', function (): void {
    app()->instance(MemberDirectory::class, new NullMemberDirectory);

    $list = personalisatieLijst([['email' => 'ref@voetbal.be', 'name' => 'Piet Van Fluiten']]);

    $result = iterator_to_array(personalisatieAudience()->resolve(['list_id' => $list->id]), false);

    expect($result[0]->personalization['voornaam'])->toBe('Piet')
        ->and($result[0]->personalization['achternaam'])->toBe('Van Fluiten')
        ->and($result[0]->personalization['aanspreking'])->toBe('Piet');
});

it('valt terug op beste bij een externe zonder naam', function (): void {
    app()->instance(MemberDirectory::class, new NullMemberDirectory);

    $list = personalisatieLijst([['email' => 'onbekend@elders.be']]);

    $result = iterator_to_array(personalisatieAudience()->resolve(['list_id' => $list->id]), false);

    // Een mail die met een lege begroeting vertrekt leest als een fout.
    expect($result[0]->personalization['aanspreking'])->toBe('beste');
});

it('valt terug op adres en label wanneer de ledenbron geen kandidaat geeft', function (): void {
    app()->instance(MemberDirectory::class, new class implements MemberDirectory
    {
        public function search(string $term, int $limit = 25): array
        {
            return [];
        }

        public function labelFor(int $memberId): ?string
        {
            return 'Jan Peeters';
        }

        public function emailFor(int $memberId): ?string
        {
            return 'jan@telenet.be';
        }

        public function candidateFor(int $memberId): ?RecipientCandidate
        {
            return null;
        }
    });

    $list = personalisatieLijst([['member_id' => 9]]);

    $result = iterator_to_array(personalisatieAudience()->resolve(['list_id' => $list->id]), false);

    // Stil overslaan zou betekenen dat iemand die bewust aan de lijst is
    // toegevoegd, zonder melding uit de mailing verdwijnt.
    expect($result)->toHaveCount(1)
        ->and($result[0]->email)->toBe('jan@telenet.be')
        ->and($result[0]->name)->toBe('Jan Peeters');
});

it('slaat een lid over dat de ledenbron helemaal niet kent', function (): void {
    app()->instance(MemberDirectory::class, new NullMemberDirectory);

    $list = personalisatieLijst([['member_id' => 99]]);

    expect(iterator_to_array(personalisatieAudience()->resolve(['list_id' => $list->id]), false))->toBeEmpty();
});
