<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Enums\LegalBasis;
use RvWaarloos\RvMail\Enums\MailCategory;

it('steunt operationele mail op de lidmaatschapsovereenkomst', function (MailCategory $category): void {
    expect($category->legalBasis())->toBe(LegalBasis::Contract)
        ->and($category->isOptOutable())->toBeFalse();
})->with([
    MailCategory::Transactioneel,
    MailCategory::Operationeel,
    MailCategory::Permanentie,
]);

it('maakt enkel clubnieuws uitschrijfbaar', function (): void {
    expect(MailCategory::Nieuws->legalBasis())->toBe(LegalBasis::LegitimateInterest)
        ->and(MailCategory::Nieuws->isOptOutable())->toBeTrue()
        ->and(MailCategory::Nieuws->requiresUnsubscribeLink())->toBeTrue();
});

it('kent geen commerciële of externe categorie', function (): void {
    $values = array_map(fn (MailCategory $c): string => $c->value, MailCategory::cases());

    expect($values)->not->toContain('commercieel')
        ->and($values)->not->toContain('extern');
});
