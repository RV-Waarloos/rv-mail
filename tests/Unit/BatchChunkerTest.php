<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Transport\BatchChunker;
use RvWaarloos\RvMail\Transport\BulkMessage;

function bericht(int $id, string $email, int $htmlBytes = 100): BulkMessage
{
    return new BulkMessage(
        recipientId: $id,
        recipientUlid: 'ulid'.$id,
        email: $email,
        name: 'Naam '.$id,
        subject: 'Onderwerp',
        html: str_repeat('x', $htmlBytes),
        text: 'tekst',
        personalization: [],
        tags: ['campaign:test'],
    );
}

function afzender(): array
{
    return ['address' => 'info@mail.rvwaarloos.be', 'name' => 'RV Waarloos'];
}

it('respecteert de maximale batchgrootte', function (): void {
    $chunker = new BatchChunker(maxMessages: 3, maxPayloadBytes: 10_000_000, spreadSharedAddresses: false);

    $messages = array_map(fn (int $i): BulkMessage => bericht($i, "lid{$i}@telenet.be"), range(1, 7));

    $batches = $chunker->chunk($messages, afzender(), null, false);

    expect($batches)->toHaveCount(3)
        ->and($batches[0]['messages'])->toHaveCount(3)
        ->and($batches[2]['messages'])->toHaveCount(1);
});

it('breekt ook af op payloadgrootte, niet alleen op aantal', function (): void {
    // 500 berichten van 80 KB zit rond de 40 MB, tegen een plafond van 50 MB.
    // Daarom bewaakt de chunker beide grenzen tegelijk.
    $chunker = new BatchChunker(maxMessages: 100, maxPayloadBytes: 5_000, spreadSharedAddresses: false);

    $messages = array_map(fn (int $i): BulkMessage => bericht($i, "lid{$i}@telenet.be", 1_500), range(1, 6));

    $batches = $chunker->chunk($messages, afzender(), null, false);

    expect(count($batches))->toBeGreaterThan(1);

    foreach ($batches as $batch) {
        expect($batch['bytes'])->toBeLessThanOrEqual(6_500);
    }
});

it('laat een enkel te groot bericht niet verdwijnen', function (): void {
    $chunker = new BatchChunker(maxMessages: 10, maxPayloadBytes: 100, spreadSharedAddresses: false);

    $batches = $chunker->chunk([bericht(1, 'lid@telenet.be', 5_000)], afzender(), null, false);

    expect($batches)->toHaveCount(1)
        ->and($batches[0]['messages'])->toHaveCount(1);
});

it('spreidt gedeelde gezinsadressen uit elkaar', function (): void {
    $chunker = new BatchChunker(maxMessages: 100, maxPayloadBytes: 10_000_000, spreadSharedAddresses: true);

    $messages = [
        bericht(1, 'gezin@telenet.be'),
        bericht(2, 'gezin@telenet.be'),
        bericht(3, 'gezin@telenet.be'),
        bericht(4, 'los1@telenet.be'),
        bericht(5, 'los2@telenet.be'),
    ];

    $batches = $chunker->chunk($messages, afzender(), null, false);
    $order = array_map(fn (BulkMessage $m): string => $m->email, $batches[0]['messages']);

    // Geen twee identieke adressen meer naast elkaar.
    for ($i = 1, $n = count($order); $i < $n; $i++) {
        expect($order[$i])->not->toBe($order[$i - 1]);
    }
});

it('laat de volgorde ongemoeid wanneer niets gedeeld wordt', function (): void {
    $chunker = new BatchChunker(maxMessages: 100, maxPayloadBytes: 10_000_000, spreadSharedAddresses: true);

    $messages = array_map(fn (int $i): BulkMessage => bericht($i, "lid{$i}@telenet.be"), range(1, 4));

    $batches = $chunker->chunk($messages, afzender(), null, false);

    expect(array_map(fn (BulkMessage $m): int => $m->recipientId, $batches[0]['messages']))
        ->toBe([1, 2, 3, 4]);
});

it('geeft een lege lijst terug zonder berichten', function (): void {
    $chunker = new BatchChunker(maxMessages: 100, maxPayloadBytes: 1_000, spreadSharedAddresses: true);

    expect($chunker->chunk([], afzender(), null, false))->toBe([]);
});
