<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Enums\MailEventType;
use RvWaarloos\RvMail\Webhooks\WebhookPayload;

function ruwePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'activity.hard_bounced',
        'webhook_id' => 'wh_1',
        'created_at' => '2026-09-12T10:00:00.000000Z',
        'data' => [
            'id' => 'evt_1',
            'type' => 'hard_bounced',
            'created_at' => '2026-09-12T10:00:05.000000Z',
            'email' => [
                'id' => 'msg_1',
                'tags' => ['campaign:01JCAMP', 'cat:nieuws', 'rcpt:01JRCPT'],
                'recipient' => ['email' => 'jan@telenet.be'],
            ],
            'morph' => ['reason' => 'Mailbox does not exist', 'bounce_code' => '550'],
        ],
    ], $overrides);
}

it('leest de sleutelvelden uit een geneste payload', function (): void {
    $payload = WebhookPayload::fromArray(ruwePayload());

    expect($payload->eventId)->toBe('evt_1')
        ->and($payload->eventType)->toBe(MailEventType::HardBounced)
        ->and($payload->messageId)->toBe('msg_1')
        ->and($payload->recipientEmail)->toBe('jan@telenet.be')
        ->and($payload->reason)->toBe('Mailbox does not exist');
});

it('haalt de correlatiesleutels uit de tags', function (): void {
    $payload = WebhookPayload::fromArray(ruwePayload());

    expect($payload->recipientUlid())->toBe('01JRCPT')
        ->and($payload->campaignUlid())->toBe('01JCAMP');
});

it('gebruikt het tijdstip van het event en niet dat van de levering', function (): void {
    $payload = WebhookPayload::fromArray(ruwePayload());

    expect($payload->occurredAt->toIso8601String())->toStartWith('2026-09-12T10:00:05');
});

it('herkent de testping', function (): void {
    $payload = WebhookPayload::fromArray(['type' => 'webhook.test', 'data' => ['id' => 'ping']]);

    expect($payload->isTest())->toBeTrue()
        ->and($payload->isActionable())->toBeFalse();
});

it('markeert een onbekende eventsoort als niet-actioneerbaar', function (): void {
    $payload = WebhookPayload::fromArray(ruwePayload(['type' => 'activity.opened']));

    // Openregistratie wordt niet gebruikt; komt er toch een opened-event
    // binnen, dan bewaren we het maar doen we er niets mee.
    expect($payload->isActionable())->toBeFalse();
});

it('overleeft een payload zonder tags of ontvanger', function (): void {
    $payload = WebhookPayload::fromArray([
        'type' => 'activity.sent',
        'webhook_id' => 'wh_1',
        'data' => ['id' => 'evt_2', 'email' => []],
    ]);

    expect($payload->recipientUlid())->toBeNull()
        ->and($payload->recipientEmail)->toBeNull()
        ->and($payload->eventType)->toBe(MailEventType::Sent);
});

it('verzint een sleutel wanneer het event-id ontbreekt', function (): void {
    $payload = WebhookPayload::fromArray([
        'type' => 'activity.sent',
        'webhook_id' => 'wh_9',
        'data' => ['email' => ['id' => 'msg_7']],
    ]);

    // Zonder id kunnen we niet dedupliceren, maar de levering weggooien is
    // erger dan een afgeleide sleutel gebruiken.
    expect($payload->eventId)->toContain('wh_9');
});
