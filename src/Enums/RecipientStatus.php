<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

enum RecipientStatus: string
{
    /** Staat in het snapshot en komt in aanmerking om te verzenden. */
    case Pending = 'pending';

    /** Bewust overgeslagen; de reden staat in skip_reason. */
    case Suppressed = 'suppressed';

    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case SoftBounced = 'soft_bounced';
    case HardBounced = 'hard_bounced';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In wacht',
            self::Suppressed => 'Overgeslagen',
            self::Queued => 'In wachtrij',
            self::Sent => 'Verzonden',
            self::Delivered => 'Afgeleverd',
            self::SoftBounced => 'Tijdelijk geweigerd',
            self::HardBounced => 'Definitief geweigerd',
            self::Failed => 'Mislukt',
        };
    }

    /** Telt mee voor het quotum en gaat effectief de deur uit. */
    public function isDeliverable(): bool
    {
        return $this === self::Pending;
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::HardBounced, self::Failed], true);
    }
}
