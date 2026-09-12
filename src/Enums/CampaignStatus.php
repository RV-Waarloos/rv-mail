<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Composing = 'composing';
    case Composed = 'composed';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Dispatching = 'dispatching';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Klad',
            self::Composing => 'Samenstellen',
            self::Composed => 'Samengesteld',
            self::PendingApproval => 'Wacht op goedkeuring',
            self::Scheduled => 'Ingepland',
            self::Dispatching => 'Wordt verzonden',
            self::Sent => 'Verzonden',
            self::Failed => 'Mislukt',
            self::Cancelled => 'Geannuleerd',
        };
    }

    /** Hersamenstellen mag zolang er niets vertrokken is. */
    public function canCompose(): bool
    {
        return in_array($this, [self::Draft, self::Composed, self::PendingApproval], true);
    }

    public function canDispatch(): bool
    {
        return in_array($this, [self::Composed, self::Scheduled], true);
    }

    public function canCancel(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Cancelled], true);
    }
}
