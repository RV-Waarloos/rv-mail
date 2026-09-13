<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Webhooks;

use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Enums\MailEventType;

/**
 * Leest de MailerSend-webhookstructuur uit.
 *
 * De payload is diep genest en de interessante velden zitten op wisselende
 * plekken per eventsoort. Dat uitpakwerk hoort op één plaats, niet verspreid
 * over de controller en de job.
 */
final readonly class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $tags
     */
    private function __construct(
        public string $eventId,
        public string $type,
        public ?MailEventType $eventType,
        public ?string $messageId,
        public ?string $recipientEmail,
        public Carbon $occurredAt,
        public array $tags,
        public array $raw,
        public ?string $reason = null,
        public ?string $clickedUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = self::arrayAt($payload, 'data');
        $email = self::arrayAt($data, 'email');
        $morph = self::arrayAt($data, 'morph');
        $recipient = self::arrayAt($email, 'recipient');

        $type = self::stringAt($payload, 'type') ?? 'unknown';

        return new self(
            // Zonder id kunnen we niet dedupliceren, maar een levering weggooien
            // is erger. Dan maar een afgeleide sleutel.
            eventId: self::stringAt($data, 'id')
                ?? self::stringAt($payload, 'webhook_id').':'.$type.':'.(
                    self::stringAt($email, 'id') ?? substr(hash('sha256', json_encode($payload) ?: ''), 0, 16)
                ),
            type: $type,
            eventType: MailEventType::fromWebhookType($type),
            messageId: self::stringAt($email, 'id') ?? self::stringAt(self::arrayAt($email, 'message'), 'id'),
            recipientEmail: self::stringAt($recipient, 'email'),
            occurredAt: self::timestamp($data, $payload),
            tags: self::stringList($email['tags'] ?? null),
            raw: $payload,
            reason: self::stringAt($morph, 'reason') ?? self::stringAt($morph, 'bounce_code'),
            clickedUrl: self::stringAt($morph, 'url'),
        );
    }

    /**
     * De ontvanger-ulid uit de tags. Custom headers zijn op het Hobby plan niet
     * beschikbaar, dus dit is de primaire weg terug naar de juiste rij.
     */
    public function recipientUlid(): ?string
    {
        return $this->tagValue('rcpt:');
    }

    public function campaignUlid(): ?string
    {
        return $this->tagValue('campaign:');
    }

    /** Een ping van MailerSend bij het opzetten van de webhook. */
    public function isTest(): bool
    {
        return $this->type === 'webhook.test';
    }

    public function isActionable(): bool
    {
        return $this->eventType instanceof MailEventType;
    }

    private function tagValue(string $prefix): ?string
    {
        foreach ($this->tags as $tag) {
            if (str_starts_with($tag, $prefix)) {
                $value = substr($tag, strlen($prefix));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private static function timestamp(array $data, array $payload): Carbon
    {
        $value = self::stringAt($data, 'created_at') ?? self::stringAt($payload, 'created_at');

        if ($value === null) {
            return Carbon::now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return Carbon::now();
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private static function arrayAt(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function stringAt(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_int($value) ? (string) $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
