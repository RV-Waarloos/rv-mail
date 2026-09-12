<?php

declare(strict_types=1);

use RvWaarloos\RvMail\Enums\MailEventType;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Enums\SuppressionReason;

it('zet een webhooktype om naar een event', function (): void {
    expect(MailEventType::fromWebhookType('activity.hard_bounced'))->toBe(MailEventType::HardBounced)
        ->and(MailEventType::HardBounced->webhookType())->toBe('activity.hard_bounced');
});

it('kent geen openregistratie', function (): void {
    expect(MailEventType::fromWebhookType('activity.opened'))->toBeNull()
        ->and(MailEventType::fromWebhookType('activity.opened_unique'))->toBeNull()
        ->and(MailEventType::subscribedWebhookTypes())->not->toContain('activity.opened_unique');
});

it('zet een hard bounce meteen op de suppressielijst', function (): void {
    expect(MailEventType::HardBounced->toSuppressionReason())->toBe(SuppressionReason::HardBounce)
        ->and(MailEventType::SpamComplaint->toSuppressionReason())->toBe(SuppressionReason::SpamComplaint)
        ->and(MailEventType::Delivered->toSuppressionReason())->toBeNull();
});

it('vertaalt bezorgingsevents naar een ontvangerstatus', function (): void {
    expect(MailEventType::Delivered->toRecipientStatus())->toBe(RecipientStatus::Delivered)
        ->and(MailEventType::ClickedUnique->toRecipientStatus())->toBeNull();
});

it('houdt een spamklacht onomkeerbaar', function (): void {
    expect(SuppressionReason::SpamComplaint->isReversible())->toBeFalse()
        ->and(SuppressionReason::HardBounce->isReversible())->toBeTrue();
});
