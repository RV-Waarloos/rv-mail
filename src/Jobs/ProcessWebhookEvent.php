<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RvWaarloos\RvMail\Contracts\SuppressionStore;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\MailEventType;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Enums\SuppressionReason;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\MailEvent;
use RvWaarloos\RvMail\Models\Unsubscribe;
use RvWaarloos\RvMail\Models\WebhookDelivery;
use RvWaarloos\RvMail\Webhooks\RecipientResolver;
use RvWaarloos\RvMail\Webhooks\WebhookPayload;
use Throwable;

/**
 * Verwerkt een binnengekomen webhooklevering.
 *
 * Dit is waar het eigen event-log gevuld wordt, en dat is geen kopie van het
 * MailerSend-dashboard maar de bron van waarheid: op het Hobby plan verdwijnt
 * hun activity-data na 24 uur.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $deliveryId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(RecipientResolver $resolver, SuppressionStore $suppressions): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if (! $delivery instanceof WebhookDelivery || $delivery->processed_at !== null) {
            return;
        }

        try {
            $payload = WebhookPayload::fromArray($delivery->raw_payload);

            if ($payload->isTest()) {
                // De ping bij het opzetten van de webhook. Bevestigen en verder
                // niets doen.
                $this->markProcessed($delivery);

                return;
            }

            if (! $payload->isActionable()) {
                // Een eventsoort waarop we ons niet abonneerden. Bewaren voor de
                // diagnose, maar er is niets mee te doen.
                $this->markProcessed($delivery);

                return;
            }

            $recipient = $resolver->resolve($payload);

            $this->log($payload, $recipient);
            $this->applyToRecipient($payload, $recipient);
            $this->applySuppression($payload, $recipient, $suppressions);
            $this->incrementCounters($payload, $recipient);

            $this->markProcessed($delivery);
        } catch (Throwable $exception) {
            $delivery->forceFill(['error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }

    private function log(WebhookPayload $payload, ?CampaignRecipient $recipient): void
    {
        MailEvent::query()->create([
            'campaign_id' => $recipient->campaign_id ?? $this->campaignIdFromTag($payload),
            'recipient_id' => $recipient?->id,
            'type' => $payload->eventType,
            'ms_message_id' => $payload->messageId,
            'occurred_at' => $payload->occurredAt,
            'received_at' => Carbon::now(),
            'payload' => $payload->raw,
        ]);
    }

    /**
     * Statusverloop is niet strikt oplopend: webhooks kunnen in willekeurige
     * volgorde aankomen. Een `sent` die na een `delivered` binnenvalt mag de
     * status niet terugzetten.
     */
    private function applyToRecipient(WebhookPayload $payload, ?CampaignRecipient $recipient): void
    {
        if (! $recipient instanceof CampaignRecipient) {
            return;
        }

        $attributes = [];

        if ($payload->messageId !== null && $recipient->ms_message_id === null) {
            $attributes['ms_message_id'] = $payload->messageId;
        }

        $newStatus = $payload->eventType?->toRecipientStatus();

        if ($newStatus instanceof RecipientStatus && $this->outranks($newStatus, $recipient->status)) {
            $attributes['status'] = $newStatus;
        }

        $attributes += match ($payload->eventType) {
            MailEventType::Sent => ['sent_at' => $recipient->sent_at ?? $payload->occurredAt],
            MailEventType::Delivered => ['delivered_at' => $recipient->delivered_at ?? $payload->occurredAt],
            MailEventType::ClickedUnique => ['first_clicked_at' => $recipient->first_clicked_at ?? $payload->occurredAt],
            MailEventType::SoftBounced, MailEventType::HardBounced => ['failure_reason' => $payload->reason],
            default => [],
        };

        if ($attributes !== []) {
            $recipient->forceFill($attributes)->save();
        }
    }

    /**
     * Rangorde van statussen. Een bounce wint altijd; verder telt de laatste
     * stap in de bezorging.
     */
    private function outranks(RecipientStatus $candidate, RecipientStatus $current): bool
    {
        $rank = [
            RecipientStatus::Pending->value => 0,
            RecipientStatus::Queued->value => 1,
            RecipientStatus::Sent->value => 2,
            RecipientStatus::Delivered->value => 3,
            RecipientStatus::SoftBounced->value => 4,
            RecipientStatus::Failed->value => 5,
            RecipientStatus::HardBounced->value => 6,
            RecipientStatus::Suppressed->value => 6,
        ];

        return $rank[$candidate->value] > $rank[$current->value];
    }

    private function applySuppression(
        WebhookPayload $payload,
        ?CampaignRecipient $recipient,
        SuppressionStore $suppressions,
    ): void {
        $reason = $payload->eventType?->toSuppressionReason();

        if (! $reason instanceof SuppressionReason) {
            return;
        }

        $email = $recipient->email ?? $payload->recipientEmail;

        if ($email === null || $email === '') {
            return;
        }

        if ($reason === SuppressionReason::Unsubscribe) {
            $this->recordUnsubscribe($email, $recipient, $payload);

            return;
        }

        $suppressions->suppress(
            email: $email,
            reason: $reason,
            memberId: $recipient?->member_id,
            source: 'webhook:'.$payload->type,
        );
    }

    /**
     * Een uitschrijving is categoriegebonden: wie het clubnieuws afzet, moet de
     * permanentie-oproepen blijven krijgen. Daarom geen algemene suppressie maar
     * een rij in mail_unsubscribes voor de categorie van deze campagne.
     */
    private function recordUnsubscribe(
        string $email,
        ?CampaignRecipient $recipient,
        WebhookPayload $payload,
    ): void {
        $campaign = $recipient?->campaign;

        $category = $campaign instanceof Campaign
            ? $campaign->category
            : MailCategory::Nieuws;

        if (! $category->isOptOutable()) {
            // Uitschrijven voor operationele mail bestaat niet; dan is er iets
            // misgegaan met de mapping, en een ruime suppressie zou het lid
            // onbereikbaar maken voor praktische clubzaken.
            return;
        }

        Unsubscribe::query()->updateOrCreate(
            ['email' => mb_strtolower($email), 'category' => $category],
            [
                'member_id' => $recipient?->member_id,
                'source' => 'webhook:'.$payload->type,
                'unsubscribed_at' => $payload->occurredAt,
            ],
        );
    }

    /**
     * Atomair ophogen: meerdere workers kunnen tegelijk events voor dezelfde
     * campagne verwerken.
     */
    private function incrementCounters(WebhookPayload $payload, ?CampaignRecipient $recipient): void
    {
        $campaignId = $recipient->campaign_id ?? $this->campaignIdFromTag($payload);

        if ($campaignId === null) {
            return;
        }

        $column = match ($payload->eventType) {
            MailEventType::Sent => 'count_sent',
            MailEventType::Delivered => 'count_delivered',
            MailEventType::ClickedUnique => 'count_clicked',
            MailEventType::SoftBounced => 'count_soft_bounced',
            MailEventType::HardBounced => 'count_hard_bounced',
            MailEventType::SpamComplaint => 'count_complained',
            default => null,
        };

        if ($column === null) {
            return;
        }

        DB::connection('central')->table('mail_campaigns')
            ->where('id', $campaignId)
            ->increment($column);
    }

    private function campaignIdFromTag(WebhookPayload $payload): ?int
    {
        $ulid = $payload->campaignUlid();

        if ($ulid === null) {
            return null;
        }

        $id = Campaign::query()->where('ulid', $ulid)->value('id');

        return is_int($id) ? $id : null;
    }

    private function markProcessed(WebhookDelivery $delivery): void
    {
        $delivery->forceFill(['processed_at' => Carbon::now(), 'error' => null])->save();
    }
}
