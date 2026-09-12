<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Campaigns\RecipientCandidate;
use RvWaarloos\RvMail\Campaigns\RecipientDeduplicator;
use RvWaarloos\RvMail\Enums\DedupStrategy;

/** @return list<RecipientCandidate> */
function gezinPeeters(): array
{
    return [
        new RecipientCandidate('gezin@telenet.be', 'Jan Peeters', 1),
        new RecipientCandidate('Gezin@Telenet.be', 'Mieke Peeters', 2),
        new RecipientCandidate('gezin@telenet.be ', 'Tuur Peeters', 3),
        new RecipientCandidate('losse@telenet.be', 'Sofie Willems', 4),
    ];
}

it('geeft elk gezinslid een eigen bericht bij per_member', function (): void {
    $result = (new RecipientDeduplicator)->apply(gezinPeeters(), DedupStrategy::PerMember);

    expect($result)->toHaveCount(4)
        ->and(array_map(fn ($c) => $c->memberId, $result))->toBe([1, 2, 3, 4]);
});

it('voegt gezinsleden samen bij per_email', function (): void {
    $result = (new RecipientDeduplicator)->apply(gezinPeeters(), DedupStrategy::PerEmail);

    expect($result)->toHaveCount(2);

    $gezin = $result[0];

    expect($gezin->personalization['aanspreking'])->toBe('Jan, Mieke en Tuur')
        ->and($gezin->personalization['leden'])->toHaveCount(3)
        // Een samengevoegd bericht hoort bij geen enkel lid in het bijzonder.
        ->and($gezin->memberId)->toBeNull();
});

it('behandelt hoofdletters en spaties als hetzelfde adres', function (): void {
    $impact = (new RecipientDeduplicator)->householdImpact(gezinPeeters());

    expect($impact['shared_addresses'])->toBe(1)
        ->and($impact['members_on_shared'])->toBe(3)
        ->and($impact['savings'])->toBe(2);
});

it('filtert exacte duplicaten uit overlappende doelgroepen', function (): void {
    $candidates = [
        new RecipientCandidate('jan@telenet.be', 'Jan Peeters', 1),
        new RecipientCandidate('jan@telenet.be', 'Jan Peeters', 1),
    ];

    $result = (new RecipientDeduplicator)->apply($candidates, DedupStrategy::PerMember);

    expect($result)->toHaveCount(1);
});

it('gebruikt de voornaam als aanspreking bij een alleenstaand adres', function (): void {
    $result = (new RecipientDeduplicator)->apply(
        [new RecipientCandidate('sofie@telenet.be', 'Sofie Willems', 9)],
        DedupStrategy::PerEmail,
    );

    expect($result[0]->personalization['aanspreking'])->toBe('Sofie');
});

it('valt terug op "beste" wanneer er geen naam bekend is', function (): void {
    $result = (new RecipientDeduplicator)->apply(
        [new RecipientCandidate('anoniem@telenet.be')],
        DedupStrategy::PerEmail,
    );

    expect($result[0]->personalization['aanspreking'])->toBe('beste');
});

it('slaat kandidaten zonder e-mailadres over', function (): void {
    $result = (new RecipientDeduplicator)->apply(
        [new RecipientCandidate('', 'Zonder Adres', 7)],
        DedupStrategy::PerMember,
    );

    expect($result)->toBeEmpty();
});
