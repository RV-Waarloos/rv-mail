<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Webhooks\SignatureVerifier;

it('aanvaardt een handtekening over de ruwe body', function (): void {
    $verifier = new SignatureVerifier('geheim');
    $body = '{"type":"activity.sent"}';

    expect($verifier->verify($body, $verifier->sign($body)))->toBeTrue();
});

it('weigert wanneer de body ook maar een byte verschilt', function (): void {
    $verifier = new SignatureVerifier('geheim');
    $signature = $verifier->sign('{"type":"activity.sent"}');

    // Daarom de ruwe bytes gebruiken en niet decoderen-plus-hercoderen:
    // sleutelvolgorde en spaties hoeven niet bewaard te blijven.
    expect($verifier->verify('{"type": "activity.sent"}', $signature))->toBeFalse();
});

it('weigert een handtekening van een ander secret', function (): void {
    $signature = (new SignatureVerifier('oud-secret'))->sign('body');

    expect((new SignatureVerifier('nieuw-secret'))->verify('body', $signature))->toBeFalse();
});

it('weigert wanneer er geen secret geconfigureerd is', function (): void {
    expect((new SignatureVerifier(''))->verify('body', 'wat dan ook'))->toBeFalse();
});

it('weigert een ontbrekende handtekening', function (): void {
    expect((new SignatureVerifier('geheim'))->verify('body', null))->toBeFalse();
});
