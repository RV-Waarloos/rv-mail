<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Support;

use RvWaarloos\RvMail\Campaigns\RecipientCandidate;
use RvWaarloos\RvMail\Contracts\MemberDirectory;

/**
 * Terugval wanneer de club-app geen ledenbron heeft gebonden.
 *
 * Het beheerscherm blijft dan werken, alleen zonder ledenkiezer. Beter dan een
 * exception: een package dat crasht omdat een optionele integratie ontbreekt,
 * is een package dat je niet durft te installeren.
 */
final class NullMemberDirectory implements MemberDirectory
{
    /** @return array<int, string> */
    public function search(string $term, int $limit = 25): array
    {
        return [];
    }

    public function labelFor(int $memberId): string
    {
        return 'Lid #'.$memberId;
    }

    public function emailFor(int $memberId): ?string
    {
        return null;
    }

    public function candidateFor(int $memberId): ?RecipientCandidate
    {
        return null;
    }
}
