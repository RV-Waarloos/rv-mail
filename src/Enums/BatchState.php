<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

enum BatchState: string
{
    case Pending = 'pending';

    /** Request vertrokken, uitkomst nog onbekend. Zie ReconcileCampaign. */
    case InFlight = 'in_flight';

    case Accepted = 'accepted';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In wacht',
            self::InFlight => 'Onderweg',
            self::Accepted => 'Aanvaard',
            self::Completed => 'Afgerond',
            self::Failed => 'Mislukt',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
