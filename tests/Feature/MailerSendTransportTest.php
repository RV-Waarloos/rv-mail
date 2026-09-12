<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RvWaarloos\RvMail\Exceptions\TransportRateLimited;
use RvWaarloos\RvMail\Exceptions\TransportRejected;
use RvWaarloos\RvMail\Exceptions\TransportUnavailable;
use RvWaarloos\RvMail\Models\CampaignBatch;
use RvWaarloos\RvMail\Transport\BulkBatch;
use RvWaarloos\RvMail\Transport\BulkMessage;
use RvWaarloos\RvMail\Transport\MailerSendBulkTransport;

function testBatch(): BulkBatch
{
    return new BulkBatch(
        record: new CampaignBatch(['id' => 1]),
        messages: [
            new BulkMessage(
                recipientId: 1,
                recipientUlid: '01JABC',
                email: 'jan@telenet.be',
                name: 'Jan Peeters',
                subject: 'Clubnieuws',
                html: '<p>Dag</p>',
                text: 'Dag',
                personalization: [],
                tags: ['campaign:01J'],
            ),
        ],
        from: ['address' => 'info@rvwaarloos.be', 'name' => 'RV Waarloos'],
        replyTo: null,
        trackClicks: false,
        requestUlid: '01JREQ',
    );
}

function transport(): MailerSendBulkTransport
{
    return new MailerSendBulkTransport('test-key');
}

it('geeft het bulk_email_id terug bij een geslaagde request', function (): void {
    Http::fake([
        '*/bulk-email' => Http::response(['message' => 'ok', 'bulk_email_id' => 'bulk_123'], 202),
    ]);

    $result = transport()->send(testBatch());

    expect($result->bulkEmailId)->toBe('bulk_123')
        ->and($result->accepted)->toBe(1);
});

it('leest retry-after uit de header bij een 429', function (): void {
    // Dit is de reden om de HTTP client te gebruiken in plaats van de SDK:
    // zonder toegang tot deze headers kun je het tempo niet netjes volgen.
    Http::fake([
        '*/bulk-email' => Http::response([], 429, ['retry-after' => '45']),
    ]);

    try {
        transport()->send(testBatch());
        $this->fail('Had TransportRateLimited moeten gooien.');
    } catch (TransportRateLimited $exception) {
        expect($exception->retryAfter)->toBe(45)
            ->and($exception->quotaExhausted)->toBeFalse();
    }
});

it('herkent een uitgeput dagquotum', function (): void {
    Http::fake([
        '*/bulk-email' => Http::response([], 429, [
            'retry-after' => '60',
            'x-apiquota-remaining' => '0',
        ]),
    ]);

    try {
        transport()->send(testBatch());
        $this->fail('Had TransportRateLimited moeten gooien.');
    } catch (TransportRateLimited $exception) {
        expect($exception->quotaExhausted)->toBeTrue();
    }
});

it('valt terug op een standaardwachttijd zonder retry-after', function (): void {
    Http::fake(['*/bulk-email' => Http::response([], 429)]);

    try {
        transport()->send(testBatch());
        $this->fail('Had TransportRateLimited moeten gooien.');
    } catch (TransportRateLimited $exception) {
        expect($exception->retryAfter)->toBe(60);
    }
});

it('gooit een rejection bij een 422 met de veldfouten erbij', function (): void {
    Http::fake([
        '*/bulk-email' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['0.to.0.email' => ['Ongeldig adres.']],
        ], 422),
    ]);

    try {
        transport()->send(testBatch());
        $this->fail('Had TransportRejected moeten gooien.');
    } catch (TransportRejected $exception) {
        expect($exception->errors)->toHaveKey('0.to.0.email');
    }
});

it('behandelt een afgewezen sleutel als onbeschikbaar', function (): void {
    Http::fake(['*/bulk-email' => Http::response([], 401)]);

    transport()->send(testBatch());
})->throws(TransportUnavailable::class);

it('leest de status van een bulk-request uit', function (): void {
    Http::fake([
        '*/bulk-email/bulk_123' => Http::response([
            'data' => [
                'id' => 'bulk_123',
                'state' => 'completed',
                'total_recipients_count' => 100,
                'suppressed_recipients_count' => 2,
                'validation_errors_count' => 1,
                'suppressed_recipients' => [['email' => 'bounce@telenet.be']],
                'validation_errors' => ['3' => ['email' => 'ongeldig']],
                'messages_id' => ['msg_a', 'msg_b'],
            ],
        ], 200),
    ]);

    $status = transport()->status('bulk_123');

    expect($status->state)->toBe('completed')
        ->and($status->isFinished())->toBeTrue()
        ->and($status->hasProblems())->toBeTrue()
        ->and($status->messageIds)->toBe(['msg_a', 'msg_b']);
});

it('geeft null terug wanneer de reconciliatie geen uitsluitsel biedt', function (): void {
    Http::fake(['*/emails*' => Http::response([], 500)]);

    // Bij twijfel niets doen: een dubbele mailing is erger dan een batch die
    // blijft hangen tot iemand kijkt.
    expect(transport()->countMessagesWithTag('campaign:01J'))->toBeNull();
});

it('telt bestaande berichten met een tag', function (): void {
    Http::fake(['*/emails*' => Http::response(['data' => [[], [], []]], 200)]);

    expect(transport()->countMessagesWithTag('campaign:01J'))->toBe(3);
});
