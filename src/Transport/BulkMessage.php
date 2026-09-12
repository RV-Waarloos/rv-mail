<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

/**
 * Eén uitgaand bericht, klaar om als element in de bulk-payload te gaan.
 *
 * De HTML is per bericht identiek; de variabele stukken zitten in
 * `personalization` en worden door MailerSend ingevuld. Dat houdt de payload
 * hanteerbaar en de rendering op één plek.
 */
final readonly class BulkMessage
{
    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $recipientId,
        public string $recipientUlid,
        public string $email,
        public ?string $name,
        public string $subject,
        public string $html,
        public string $text,
        public array $personalization,
        public array $tags,
    ) {}

    /**
     * De payload zoals MailerSend hem verwacht.
     *
     * Merk op: geen `settings.track_opens` op true, ooit. Zie de sectie over
     * gegevensbescherming — dat is geen instelling maar een uitgangspunt.
     *
     * @param  array{address: string, name: string}  $from
     * @return array<string, mixed>
     */
    public function toPayload(array $from, ?string $replyTo, bool $trackClicks): array
    {
        $payload = [
            'from' => [
                'email' => $from['address'],
                'name' => $from['name'],
            ],
            'to' => [
                array_filter([
                    'email' => $this->email,
                    'name' => $this->name,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'tags' => $this->tags,
            'settings' => [
                'track_clicks' => $trackClicks,
                'track_opens' => false,
                'track_content' => false,
            ],
            // Onderdrukt out-of-office-lussen en markeert de mail correct als bulk.
            'precedence_bulk' => true,
        ];

        if ($this->personalization !== []) {
            $payload['personalization'] = [[
                'email' => $this->email,
                'data' => $this->personalization,
            ]];
        }

        if ($replyTo !== null && $replyTo !== '') {
            $payload['reply_to'] = ['email' => $replyTo];
        }

        return $payload;
    }
}
