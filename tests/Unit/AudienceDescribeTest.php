<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Audiences\AbstractAudience;
use RvWaarloos\RvMail\Support\UnrestrictedScopeResolver;

function afdelingAudience(): AbstractAudience
{
    return new class(new UnrestrictedScopeResolver) extends AbstractAudience
    {
        public function key(): string
        {
            return 'afdeling';
        }

        public function label(): string
        {
            return 'Afdeling';
        }

        public function parameterSchema(): array
        {
            return [
                'afdeling_id' => [
                    'type' => 'select',
                    'label' => 'Afdeling',
                    'required' => true,
                    'options' => [3 => 'U15', 7 => 'Dames'],
                ],
            ];
        }

        public function resolve(array $params): iterable
        {
            return [];
        }
    };
}

it('beschrijft de gekozen parameter in mensentaal', function (): void {
    // "Afdeling" zegt niets in een overzicht; "Afdeling: U15" wel.
    expect(afdelingAudience()->describe(['afdeling_id' => 3]))->toBe('Afdeling: U15');
});

it('valt terug op het label zonder gekozen parameter', function (): void {
    expect(afdelingAudience()->describe([]))->toBe('Afdeling');
});

it('somt meerdere waarden op', function (): void {
    expect(afdelingAudience()->describe(['afdeling_id' => [3, 7]]))->toBe('Afdeling: U15, Dames');
});

it('toont de ruwe waarde wanneer die niet in de opties staat', function (): void {
    // Een afdeling die intussen verwijderd is mag geen lege beschrijving geven:
    // dan lijkt het alsof er niets gekozen was.
    expect(afdelingAudience()->describe(['afdeling_id' => 99]))->toBe('Afdeling: 99');
});
