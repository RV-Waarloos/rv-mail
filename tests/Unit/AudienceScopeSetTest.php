<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Audiences\AudienceScopeSet;

it('laat alles toe voor een onbeperkte scope', function (): void {
    $scope = AudienceScopeSet::all();

    expect($scope->allows('active_members'))->toBeTrue()
        ->and($scope->allows('afdeling', ['afdeling_id' => 99]))->toBeTrue();
});

it('laat niets toe voor een gewoon lid', function (): void {
    expect(AudienceScopeSet::none()->allows('afdeling', ['afdeling_id' => 1]))->toBeFalse();
});

it('beperkt een afdelingsverantwoordelijke tot de eigen afdeling', function (): void {
    $scope = AudienceScopeSet::of([
        'afdeling' => ['afdeling_id' => [3, 7]],
    ]);

    expect($scope->allows('afdeling', ['afdeling_id' => 3]))->toBeTrue()
        ->and($scope->allows('afdeling', ['afdeling_id' => 4]))->toBeFalse()
        // Clubbreed blijft buiten bereik, ook zonder parameters.
        ->and($scope->allows('active_members'))->toBeFalse();
});

it('laat een lege parameter de beperking niet omzeilen', function (): void {
    $scope = AudienceScopeSet::of(['afdeling' => ['afdeling_id' => [3]]]);

    expect($scope->allows('afdeling', []))->toBeFalse();
});

it('weigert zodra één waarde in een meervoudige parameter buiten bereik valt', function (): void {
    $scope = AudienceScopeSet::of(['afdeling' => ['afdeling_id' => [3, 7]]]);

    expect($scope->allows('afdeling', ['afdeling_id' => [3, 7]]))->toBeTrue()
        ->and($scope->allows('afdeling', ['afdeling_id' => [3, 9]]))->toBeFalse();
});

it('laat elke waarde toe voor een parameter zonder beperking', function (): void {
    $scope = AudienceScopeSet::of(['distribution_list' => []]);

    expect($scope->allows('distribution_list', ['list_id' => 42]))->toBeTrue();
});
