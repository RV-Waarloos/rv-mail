<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Support;

use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Models\QuotaLedgerEntry;

/**
 * De enige plek waar budget wordt afgetrokken en getoetst.
 *
 * Het Hobby plan staat 5.000 mails per rollende maand toe. Daarvan blijft een
 * deel gereserveerd voor transactionele mail, zodat een enthousiaste mailing
 * nooit het paswoordherstel kan blokkeren.
 */
final class QuotaGuard
{
    public function __construct(
        private readonly BillingPeriod $period,
    ) {}

    public static function forCurrentPeriod(): self
    {
        return new self(BillingPeriod::current());
    }

    public function period(): BillingPeriod
    {
        return $this->period;
    }

    public function limit(): int
    {
        return (int) config('rv-mail.quota.monthly_emails', 5_000);
    }

    public function transactionalReserve(): int
    {
        return (int) config('rv-mail.quota.transactional_reserve', 1_000);
    }

    public function usedInPeriod(): int
    {
        return (int) QuotaLedgerEntry::query()
            ->whereBetween('date', [$this->period->start->toDateString(), $this->period->end->toDateString()])
            ->sum('emails_sent');
    }

    /** Wat er nog is vóór de transactionele reserve. */
    public function remaining(): int
    {
        return max(0, $this->limit() - $this->usedInPeriod());
    }

    /** Wat een bulkcampagne mag opgebruiken. */
    public function availableForBulk(): int
    {
        return max(0, $this->remaining() - $this->transactionalReserve());
    }

    public function canSendBulk(int $count): bool
    {
        return $count <= $this->availableForBulk();
    }

    public function usagePercentage(): float
    {
        $limit = $this->limit();

        return $limit === 0 ? 0.0 : round($this->usedInPeriod() / $limit * 100, 1);
    }

    public function shouldWarn(): bool
    {
        return $this->usagePercentage() >= (float) config('rv-mail.quota.warn_at_percentage', 80);
    }

    public function record(int $emails, bool $transactional = false, int $apiRequests = 0, int $bulkRequests = 0): void
    {
        $date = Carbon::now()->toDateString();

        // Geen firstOrCreate: die doet SELECT-dan-INSERT en twee workers die op
        // dezelfde dag een batch afronden botsen dan op de unieke index.
        QuotaLedgerEntry::query()->insertOrIgnore([
            'date' => $date,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $increments = array_filter([
            'emails_sent' => $emails,
            'emails_transactional' => $transactional ? $emails : 0,
            'api_requests' => $apiRequests,
            'bulk_requests' => $bulkRequests,
        ], static fn (int $value): bool => $value > 0);

        if ($increments === []) {
            return;
        }

        $query = QuotaLedgerEntry::query()->where('date', $date);

        foreach ($increments as $column => $value) {
            $query->clone()->increment($column, $value);
        }
    }

    public function apiRequestsToday(): int
    {
        // Let op de precedentie: `(int) $x ?? 0` cast eerst en is daarna nooit
        // meer null, waardoor de fallback dode code is.
        $value = QuotaLedgerEntry::query()
            ->where('date', Carbon::now()->toDateString())
            ->value('api_requests');

        return is_numeric($value) ? (int) $value : 0;
    }

    public function dailyRequestsRemaining(): int
    {
        return max(0, (int) config('rv-mail.quota.daily_api_requests', 1_000) - $this->apiRequestsToday());
    }
}
