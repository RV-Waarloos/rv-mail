<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Transport\BulkMessage;

function payloadBericht(array $personalization = []): BulkMessage
{
    return new BulkMessage(
        recipientId: 1,
        recipientUlid: '01JABC',
        email: 'jan@telenet.be',
        name: 'Jan Peeters',
        subject: 'Clubnieuws',
        html: '<p>Dag</p>',
        text: 'Dag',
        personalization: $personalization,
        tags: ['campaign:01J', 'rcpt:01JABC'],
    );
}

it('zet track_opens nooit aan', function (): void {
    $payload = payloadBericht()->toPayload(
        ['address' => 'info@rvwaarloos.be', 'name' => 'RV Waarloos'],
        null,
        trackClicks: true,
    );

    expect($payload['settings']['track_opens'])->toBeFalse()
        ->and($payload['settings']['track_content'])->toBeFalse()
        ->and($payload['settings']['track_clicks'])->toBeTrue();
});

it('markeert groepsmail als bulk om out-of-office-lussen te onderdrukken', function (): void {
    $payload = payloadBericht()->toPayload(
        ['address' => 'info@rvwaarloos.be', 'name' => 'RV Waarloos'],
        null,
        false,
    );

    expect($payload['precedence_bulk'])->toBeTrue();
});

it('draagt de correlatietags mee', function (): void {
    $payload = payloadBericht()->toPayload(
        ['address' => 'info@rvwaarloos.be', 'name' => 'RV Waarloos'],
        null,
        false,
    );

    // Custom headers zijn Professional-only, dus tags zijn de enige weg terug
    // van een webhook-event naar de juiste ontvanger.
    expect($payload['tags'])->toContain('rcpt:01JABC');
});

it('laat personalization weg wanneer er niets te personaliseren valt', function (): void {
    $payload = payloadBericht()->toPayload(
        ['address' => 'info@rvwaarloos.be', 'name' => 'RV Waarloos'],
        null,
        false,
    );

    expect($payload)->not->toHaveKey('personalization');
});

it('neemt reply_to over wanneer die gezet is', function (): void {
    $payload = payloadBericht(['aanspreking' => 'Jan'])->toPayload(
        ['address' => 'info@rvwaarloos.be', 'name' => 'RV Waarloos'],
        'secretariaat@rvwaarloos.be',
        false,
    );

    expect($payload['reply_to']['email'])->toBe('secretariaat@rvwaarloos.be')
        ->and($payload['personalization'][0]['data']['aanspreking'])->toBe('Jan');
});
