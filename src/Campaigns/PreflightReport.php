<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use RvWaarloos\RvMail\Enums\DedupStrategy;
use RvWaarloos\RvMail\Enums\SkipReason;

/**
 * Wat de opsteller te zien krijgt vóór hij op verzenden duwt.
 *
 * Drie dingen moeten hier expliciet zijn: hoeveel mensen je bereikt, wie je
 * niet bereikt en waarom, en wat het kost aan quotum.
 */
final readonly class PreflightReport
{
    /**
     * @param  array<string, int>  $skipped
     * @param  array{shared_addresses: int, members_on_shared: int, savings: int}  $householdImpact
     */
    public function __construct(
        public int $sendable,
        public array $skipped,
        public array $householdImpact,
        public DedupStrategy $strategy,
        public int $quotaUsed,
        public int $quotaLimit,
        public int $quotaAvailableForBulk,
        public bool $requiresApproval,
        public bool $requiresConfirmation,
    ) {}

    public static function fromComposition(
        CompositionResult $composition,
        DedupStrategy $strategy,
        int $quotaUsed,
        int $quotaLimit,
        int $quotaAvailableForBulk,
        bool $requiresApproval,
        bool $requiresConfirmation,
    ): self {
        return new self(
            sendable: $composition->sendable,
            skipped: $composition->skipped,
            householdImpact: $composition->householdImpact,
            strategy: $strategy,
            quotaUsed: $quotaUsed,
            quotaLimit: $quotaLimit,
            quotaAvailableForBulk: $quotaAvailableForBulk,
            requiresApproval: $requiresApproval,
            requiresConfirmation: $requiresConfirmation,
        );
    }

    public function fitsWithinQuota(): bool
    {
        return $this->sendable <= $this->quotaAvailableForBulk;
    }

    public function quotaAfterSending(): int
    {
        return $this->quotaUsed + $this->sendable;
    }

    /** Aantal mails boven het plan, tegen het metered tarief. */
    public function overage(): int
    {
        return max(0, $this->quotaAfterSending() - $this->quotaLimit);
    }

    /**
     * Toont het verschil tussen beide strategieën zodra er gedeelde adressen in
     * de selectie zitten, zodat het afzetten van de standaard een geïnformeerde
     * keuze is.
     */
    public function hasSharedAddresses(): bool
    {
        return $this->householdImpact['shared_addresses'] > 0;
    }

    public function potentialSavings(): int
    {
        return $this->strategy === DedupStrategy::PerMember
            ? $this->householdImpact['savings']
            : 0;
    }

    /** @return array<string, int> */
    public function skippedWithLabels(): array
    {
        $labelled = [];

        foreach ($this->skipped as $reason => $count) {
            $case = SkipReason::tryFrom($reason);
            $labelled[$case?->label() ?? $reason] = $count;
        }

        return $labelled;
    }
}
