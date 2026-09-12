<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

enum SuppressionReason: string
{
    case HardBounce = 'hard_bounce';
    case SpamComplaint = 'spam_complaint';
    case Unsubscribe = 'unsubscribe';
    case Manual = 'manual';
    case Invalid = 'invalid';

    public function label(): string
    {
        return match ($this) {
            self::HardBounce => 'Definitief geweigerd',
            self::SpamComplaint => 'Als spam gemarkeerd',
            self::Unsubscribe => 'Uitgeschreven',
            self::Manual => 'Handmatig toegevoegd',
            self::Invalid => 'Ongeldig adres',
        };
    }

    /**
     * Een hard bounce kan hersteld zijn (mailbox weer actief); een spamklacht
     * heffen we nooit automatisch op.
     */
    public function isReversible(): bool
    {
        return in_array($this, [self::HardBounce, self::Manual, self::Invalid], true);
    }
}
