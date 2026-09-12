<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use RvWaarloos\RvMail\Enums\DedupStrategy;

/**
 * Verwerkt gezinnen die één e-mailadres delen.
 *
 * Standaard krijgt elk lid zijn eigen bericht (per_member). Dat kost credits —
 * drie kinderen op één adres zijn drie mails — maar bij persoonsgebonden inhoud
 * is samenvoegen ronduit fout. Per mailing kan naar per_email geschakeld worden.
 */
final class RecipientDeduplicator
{
    /**
     * Voegt samen volgens de strategie en levert altijd een lijst op waarin elk
     * adres-lid-paar hoogstens één keer voorkomt.
     *
     * Bij per_member blijven alle leden staan, maar exacte duplicaten (hetzelfde
     * lid twee keer, bijvoorbeeld omdat twee doelgroepen overlappen) verdwijnen.
     *
     * @param  iterable<RecipientCandidate>  $candidates
     * @return list<RecipientCandidate>
     */
    public function apply(iterable $candidates, DedupStrategy $strategy): array
    {
        $households = $this->groupByAddress($candidates);

        return $strategy === DedupStrategy::PerEmail
            ? $this->mergeHouseholds($households)
            : $this->flatten($households);
    }

    /**
     * Hoeveel mails bespaart samenvoegen? Gebruikt door het preflight-scherm,
     * zodat het afzetten van de standaard een geïnformeerde keuze is en geen
     * verstopte instelling.
     *
     * @param  iterable<RecipientCandidate>  $candidates
     * @return array{shared_addresses: int, members_on_shared: int, savings: int}
     */
    public function householdImpact(iterable $candidates): array
    {
        $households = $this->groupByAddress($candidates);

        $sharedAddresses = 0;
        $membersOnShared = 0;

        foreach ($households as $members) {
            if (count($members) > 1) {
                $sharedAddresses++;
                $membersOnShared += count($members);
            }
        }

        return [
            'shared_addresses' => $sharedAddresses,
            'members_on_shared' => $membersOnShared,
            'savings' => $membersOnShared - $sharedAddresses,
        ];
    }

    /**
     * @param  iterable<RecipientCandidate>  $candidates
     * @return array<string, list<RecipientCandidate>>
     */
    private function groupByAddress(iterable $candidates): array
    {
        /** @var array<string, list<RecipientCandidate>> $households */
        $households = [];

        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($candidates as $candidate) {
            if (! $candidate->hasEmail()) {
                continue;
            }

            $address = $candidate->normalizedEmail();

            // Exacte duplicaten wegfilteren: twee overlappende doelgroepen mogen
            // niet tot twee mails leiden.
            $identity = $address.'|'.($candidate->memberId ?? 'x');

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $households[$address][] = $candidate;
        }

        return $households;
    }

    /**
     * @param  array<string, list<RecipientCandidate>>  $households
     * @return list<RecipientCandidate>
     */
    private function flatten(array $households): array
    {
        /** @var list<RecipientCandidate> $flat */
        $flat = [];

        foreach ($households as $members) {
            foreach ($members as $member) {
                $flat[] = $member;
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, list<RecipientCandidate>>  $households
     * @return list<RecipientCandidate>
     */
    private function mergeHouseholds(array $households): array
    {
        /** @var list<RecipientCandidate> $merged */
        $merged = [];

        foreach ($households as $members) {
            $primary = $members[0];

            if (count($members) === 1) {
                $merged[] = $primary->withPersonalization([
                    'aanspreking' => $primary->firstName() ?? 'beste',
                    'leden' => $this->memberSummaries($members),
                ]);

                continue;
            }

            $merged[] = new RecipientCandidate(
                email: $primary->email,
                name: $this->joinNames($members),
                // Een samengevoegd bericht hoort bij geen enkel lid in het
                // bijzonder, dus geen member_id. Wie het ontving staat in de
                // personalisatie én in het snapshot.
                memberId: null,
                personalization: array_merge($primary->personalization, [
                    'aanspreking' => $this->joinFirstNames($members),
                    'leden' => $this->memberSummaries($members),
                ]),
                anonymized: false,
            );
        }

        return $merged;
    }

    /**
     * @param  list<RecipientCandidate>  $members
     * @return list<array{naam: string|null, voornaam: string|null, member_id: int|null}>
     */
    private function memberSummaries(array $members): array
    {
        return array_map(
            static fn (RecipientCandidate $candidate): array => [
                'naam' => $candidate->name,
                'voornaam' => $candidate->firstName(),
                'member_id' => $candidate->memberId,
            ],
            $members,
        );
    }

    /**
     * "Jan en Mieke", of "Jan, Mieke en Tuur".
     *
     * @param  list<RecipientCandidate>  $members
     */
    private function joinFirstNames(array $members): string
    {
        $names = array_values(array_filter(array_map(
            static fn (RecipientCandidate $candidate): ?string => $candidate->firstName(),
            $members,
        )));

        return $this->joinWithEn($names) ?? 'beste';
    }

    /**
     * @param  list<RecipientCandidate>  $members
     */
    private function joinNames(array $members): ?string
    {
        $names = array_values(array_filter(array_map(
            static fn (RecipientCandidate $candidate): ?string => $candidate->name,
            $members,
        )));

        return $this->joinWithEn($names);
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinWithEn(array $parts): ?string
    {
        $parts = array_values(array_unique($parts));

        if ($parts === []) {
            return null;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' en '.$last;
    }
}
