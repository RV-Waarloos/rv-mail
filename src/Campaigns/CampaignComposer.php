<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RvWaarloos\RvMail\Audiences\AudienceRegistry;
use RvWaarloos\RvMail\Contracts\SuppressionStore;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Enums\SkipReason;
use RvWaarloos\RvMail\Enums\SuppressionReason;
use RvWaarloos\RvMail\Exceptions\AudienceNotAuthorized;
use RvWaarloos\RvMail\Exceptions\CampaignNotComposable;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\Unsubscribe;

/**
 * Zet een doelgroep om in een vast snapshot van bestemmelingen.
 *
 * Het snapshot wordt gematerialiseerd op het moment van samenstellen, niet pas
 * bij verzending. Zo weet je achteraf exact wie wat kreeg, ook als het
 * lidmaatschap nadien wijzigt — hetzelfde principe als FixtureSnapshot.
 */
final class CampaignComposer
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private readonly AudienceRegistry $audiences,
        private readonly RecipientDeduplicator $deduplicator,
        private readonly SuppressionStore $suppressions,
    ) {}

    /**
     * @throws CampaignNotComposable
     * @throws AudienceNotAuthorized
     */
    public function compose(Campaign $campaign, ?Authorizable $actor = null): CompositionResult
    {
        if (! $campaign->status->canCompose()) {
            throw CampaignNotComposable::inStatus($campaign->status);
        }

        $audience = $this->audiences->get($campaign->audience_type);
        $params = $campaign->audience_params ?? [];

        // De controle draait hier én opnieuw bij het verzenden: daartussen kan
        // een rol ingetrokken zijn.
        if ($actor instanceof Authorizable && ! $audience->authorize($actor, $params)) {
            throw AudienceNotAuthorized::forAudience($audience->key());
        }

        $campaign->forceFill(['status' => CampaignStatus::Composing])->save();

        $candidates = iterator_to_array($this->toList($audience->resolve($params)), false);
        $resolved = count($candidates);

        $householdImpact = $this->deduplicator->householdImpact($candidates);
        $candidates = $this->deduplicator->apply($candidates, $campaign->dedup_strategy);

        $skipped = [];
        $rows = [];
        $sendable = 0;

        $suppressionReasons = $this->suppressions->reasonsFor(
            array_map(
                static fn (RecipientCandidate $candidate): string => $candidate->normalizedEmail(),
                $candidates,
            ),
        );

        $unsubscribed = $this->unsubscribedAddresses($campaign, $candidates);

        foreach ($candidates as $candidate) {
            $reason = $this->skipReasonFor($candidate, $suppressionReasons, $unsubscribed);

            if ($reason instanceof SkipReason) {
                $skipped[$reason->value] = ($skipped[$reason->value] ?? 0) + 1;
            } else {
                $sendable++;
            }

            $rows[] = $this->toRow($campaign, $candidate, $reason);
        }

        $this->replaceSnapshot($campaign, $rows);

        $campaign->forceFill([
            'status' => CampaignStatus::Composed,
            'composed_at' => now(),
            'recipients_total' => count($rows),
            'recipients_sendable' => $sendable,
            'count_suppressed' => count($rows) - $sendable,
        ])->save();

        return new CompositionResult(
            resolved: $resolved,
            sendable: $sendable,
            skipped: $skipped,
            householdImpact: $householdImpact,
        );
    }

    /**
     * @param  iterable<RecipientCandidate>  $candidates
     * @return list<RecipientCandidate>
     */
    private function toList(iterable $candidates): array
    {
        return is_array($candidates)
            ? array_values($candidates)
            : iterator_to_array($candidates, false);
    }

    /**
     * Adressen die zich voor deze categorie hebben uitgeschreven.
     *
     * Enkel opvragen wanneer de categorie uitschrijfbaar is: bij operationele
     * mail bestaat er geen uitschrijving, en die query zou dan suggereren dat
     * ze wel bestaat.
     *
     * @param  list<RecipientCandidate>  $candidates
     * @return array<string, true>
     */
    private function unsubscribedAddresses(Campaign $campaign, array $candidates): array
    {
        if (! $campaign->category->isOptOutable() || $candidates === []) {
            return [];
        }

        $emails = array_map(
            static fn (RecipientCandidate $candidate): string => $candidate->normalizedEmail(),
            $candidates,
        );

        $found = [];

        foreach (array_chunk($emails, self::CHUNK_SIZE) as $chunk) {
            $addresses = Unsubscribe::query()
                ->where('category', $campaign->category->value)
                ->whereIn('email', $chunk)
                ->pluck('email');

            foreach ($addresses as $address) {
                $found[mb_strtolower($address)] = true;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, SuppressionReason>  $suppressions
     * @param  array<string, true>  $unsubscribed
     */
    private function skipReasonFor(
        RecipientCandidate $candidate,
        array $suppressions,
        array $unsubscribed,
    ): ?SkipReason {
        if (! $candidate->hasEmail()) {
            return SkipReason::NoEmailAddress;
        }

        if ($candidate->anonymized) {
            return SkipReason::Anonymized;
        }

        $address = $candidate->normalizedEmail();

        if (array_key_exists($address, $suppressions)) {
            return SkipReason::Suppressed;
        }

        if (array_key_exists($address, $unsubscribed)) {
            return SkipReason::Unsubscribed;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Campaign $campaign, RecipientCandidate $candidate, ?SkipReason $reason): array
    {
        $now = now();

        return [
            'ulid' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'member_id' => $candidate->memberId,
            'email' => $candidate->normalizedEmail(),
            'name' => $candidate->name,
            'personalization' => json_encode($candidate->personalization, JSON_THROW_ON_ERROR),
            'status' => $reason instanceof SkipReason
                ? RecipientStatus::Suppressed->value
                : RecipientStatus::Pending->value,
            'skip_reason' => $reason?->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Hersamenstellen vervangt het volledige snapshot. Dat mag, want de status
     * laat het alleen toe zolang er niets vertrokken is.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceSnapshot(Campaign $campaign, array $rows): void
    {
        DB::transaction(function () use ($campaign, $rows): void {
            CampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->delete();

            foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
                CampaignRecipient::query()->insert($chunk);
            }
        });
    }
}
