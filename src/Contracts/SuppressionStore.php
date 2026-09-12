<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Contracts;

use RvWaarloos\RvMail\Enums\SuppressionReason;

interface SuppressionStore
{
    public function isSuppressed(string $email): bool;

    /**
     * Bulkvariant voor het samenstellen: één query in plaats van duizend.
     *
     * @param  list<string>  $emails
     * @return array<string, SuppressionReason> geïndexeerd op genormaliseerd adres
     */
    public function reasonsFor(array $emails): array;

    public function suppress(
        string $email,
        SuppressionReason $reason,
        ?int $memberId = null,
        ?string $source = null,
    ): void;

    public function release(string $email): bool;
}
