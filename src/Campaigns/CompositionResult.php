<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use RvWaarloos\RvMail\Enums\SkipReason;

/**
 * De uitkomst van het samenstellen: hoeveel bestemmelingen er zijn, en wie om
 * welke reden is overgeslagen.
 *
 * Die tweede helft is meestal wat men achteraf zoekt ("waarom heeft Peter die
 * mail niet gekregen?"), dus ze verdwijnt niet.
 */
final readonly class CompositionResult
{
    /**
     * @param  array<string, int>  $skipped  reden => aantal
     * @param  array{shared_addresses: int, members_on_shared: int, savings: int}  $householdImpact
     */
    public function __construct(
        public int $resolved,
        public int $sendable,
        public array $skipped,
        public array $householdImpact,
    ) {}

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }

    public function skippedFor(SkipReason $reason): int
    {
        return $this->skipped[$reason->value] ?? 0;
    }

    public function isEmpty(): bool
    {
        return $this->sendable === 0;
    }
}
