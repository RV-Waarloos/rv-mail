<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

use RvWaarloos\RvMail\Models\CampaignBatch;

/**
 * Een batch zoals hij de deur uitgaat: de berichten plus de afzendergegevens.
 *
 * `record` mag null zijn. Een testverzending is één bericht dat nergens bij
 * hoort en geen batchrij verdient — maar hij moet wel door hetzelfde transport,
 * anders heeft elke app die dit package gebruikt twee manieren om mail te
 * versturen en moet ook een app die alleen campagnes opstelt een mailer
 * configureren.
 */
final readonly class BulkBatch
{
    /**
     * @param  list<BulkMessage>  $messages
     * @param  array{address: string, name: string}  $from
     */
    public function __construct(
        public ?CampaignBatch $record,
        public array $messages,
        public array $from,
        public ?string $replyTo,
        public bool $trackClicks,
        public string $requestUlid,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function toPayload(): array
    {
        return array_map(
            fn (BulkMessage $message): array => $message->toPayload(
                $this->from,
                $this->replyTo,
                $this->trackClicks,
            ),
            $this->messages,
        );
    }

    public function size(): int
    {
        return count($this->messages);
    }

    /** @return list<int> */
    public function recipientIds(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (BulkMessage $message): int => $message->recipientId,
                $this->messages,
            ),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /** Ontvanger opzoeken op basis van de index in de payload, voor 422-fouten. */
    public function messageAt(int $index): ?BulkMessage
    {
        return $this->messages[$index] ?? null;
    }

    /** Een losse verzending die niet bij een campagnebatch hoort. */
    public function isStandalone(): bool
    {
        return $this->record === null;
    }
}
