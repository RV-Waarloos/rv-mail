<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Audiences\AudienceRegistry;
use RvWaarloos\RvMail\Audiences\DistributionListAudience;
use RvWaarloos\RvMail\Models\DistributionList;
use RvWaarloos\RvMail\Support\UnrestrictedScopeResolver;

function lijst(string $naam, bool $actief = true): DistributionList
{
    return DistributionList::query()->create([
        'name' => $naam,
        'slug' => str($naam)->slug()->value(),
        'is_active' => $actief,
    ]);
}

function lijstAudience(): DistributionListAudience
{
    return new DistributionListAudience(new UnrestrictedScopeResolver);
}

it('biedt de actieve lijsten aan als keuzeopties', function (): void {
    $eetfestijn = lijst('Eetfestijn 2026');
    $oud = lijst('Kerstmarkt 2024', actief: false);

    $schema = lijstAudience()->parameterSchema();

    expect($schema)->toHaveKey('list_id')
        ->and($schema['list_id']['options'])->toHaveKey($eetfestijn->id)
        // Een niet-actieve lijst blijft bestaan maar is niet kiesbaar.
        ->and($schema['list_id']['options'])->not->toHaveKey($oud->id);
});

it('markeert de parameter als verplicht', function (): void {
    expect(lijstAudience()->parameterSchema()['list_id']['required'])->toBeTrue();
});

it('beschrijft welke lijst gekozen is', function (): void {
    $list = lijst('Eetfestijn 2026');

    expect(lijstAudience()->describe(['list_id' => $list->id]))
        ->toBe('Distributielijst: Eetfestijn 2026');
});

it('zegt het wanneer er nog geen lijst gekozen is', function (): void {
    expect(lijstAudience()->describe([]))->toBe('Distributielijst (nog niet gekozen)');
});

it('geeft een lege optielijst zonder actieve lijsten', function (): void {
    // Het formulier moet dan nog steeds renderen, met een lege keuzelijst in
    // plaats van een fout.
    expect(lijstAudience()->parameterSchema()['list_id']['options'])->toBe([]);
});

it('registreert de doelgroep met zijn parameterschema', function (): void {
    $registry = app(AudienceRegistry::class);
    $registry->register(lijstAudience());

    expect($registry->get('distribution_list')->parameterSchema())->toHaveKey('list_id');
});
