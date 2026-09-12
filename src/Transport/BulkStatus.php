<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

/**
 * De status van een bulk-request.
 *
 * `validationErrors` is geïndexeerd op de positie in de oorspronkelijke payload;
 * `suppressed` op e-mailadres. Beide komen terug in de response van
 * GET /v1/bulk-email/{id}.
 */
final readonly class BulkStatus
{
    /**
     * @param  array<int|string, mixed>  $validationErrors
     * @param  array<int|string, mixed>  $suppressed
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public string $bulkEmailId,
        public string $state,
        public int $totalRecipients,
        public int $suppressedCount,
        public int $validationErrorsCount,
        public array $validationErrors = [],
        public array $suppressed = [],
        public array $messageIds = [],
    ) {}

    public function isFinished(): bool
    {
        return in_array($this->state, ['completed', 'failed'], true);
    }

    public function hasProblems(): bool
    {
        return $this->suppressedCount > 0 || $this->validationErrorsCount > 0;
    }
}
