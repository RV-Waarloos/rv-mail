<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Support;

use Illuminate\Support\Carbon;

/**
 * Het rollende venster waarover MailerSend telt.
 *
 * MailerSend rekent per 30 dagen vanaf de abonnementsdatum, niet per
 * kalendermaand, en biedt geen API om het verbruik op te vragen. Vandaar een
 * eigen berekening op basis van een geconfigureerde startdag.
 */
final readonly class BillingPeriod
{
    public function __construct(
        public Carbon $start,
        public Carbon $end,
    ) {}

    public static function current(?Carbon $now = null): self
    {
        $now = ($now ?? Carbon::now())->copy()->startOfDay();
        $day = (int) config('rv-mail.quota.billing_period_start_day', 1);
        $day = max(1, min(28, $day));

        $start = $now->copy()->day($day);

        if ($start->greaterThan($now)) {
            $start = $start->subMonthNoOverflow();
        }

        return new self($start, $start->copy()->addMonthNoOverflow()->subDay()->endOfDay());
    }

    public function contains(Carbon $moment): bool
    {
        return $moment->betweenIncluded($this->start, $this->end);
    }

    public function daysRemaining(?Carbon $now = null): int
    {
        return (int) ($now ?? Carbon::now())->startOfDay()->diffInDays($this->end, absolute: false);
    }

    public function label(): string
    {
        return $this->start->format('d/m/Y').' — '.$this->end->format('d/m/Y');
    }
}
