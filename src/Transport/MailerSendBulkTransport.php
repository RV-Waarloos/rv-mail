<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Exceptions\TransportRateLimited;
use RvWaarloos\RvMail\Exceptions\TransportRejected;
use RvWaarloos\RvMail\Exceptions\TransportUnavailable;

/**
 * Praat rechtstreeks met de MailerSend API.
 *
 * Bewust niet via mailersend/mailersend-php: voor de 429-afhandeling hebben we
 * `retry-after`, `x-ratelimit-remaining` en `x-apiquota-remaining` uit de
 * responseheaders nodig, en die zijn met de Laravel HTTP client eenvoudiger te
 * bereiken. Het gaat bovendien om drie endpoints, en Http::fake() maakt testen
 * een stuk eenvoudiger dan een SDK mocken.
 */
final class MailerSendBulkTransport implements BulkTransport
{
    private const string BASE_URL = 'https://api.mailersend.com/v1';

    /** Wanneer MailerSend geen retry-after meegeeft. */
    private const int DEFAULT_RETRY_AFTER = 60;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 30,
    ) {}

    public function send(BulkBatch $batch): BulkDispatchResult
    {
        $response = $this->request()
            ->post('/bulk-email', $batch->toPayload());

        $this->guard($response);

        /** @var array{bulk_email_id?: string, message?: string} $body */
        $body = $response->json() ?? [];

        $bulkEmailId = $body['bulk_email_id'] ?? null;

        if (! is_string($bulkEmailId) || $bulkEmailId === '') {
            throw new TransportUnavailable('MailerSend gaf geen bulk_email_id terug.');
        }

        return new BulkDispatchResult(
            bulkEmailId: $bulkEmailId,
            accepted: $batch->size(),
        );
    }

    public function status(string $bulkEmailId): BulkStatus
    {
        $response = $this->request()->get("/bulk-email/{$bulkEmailId}");

        $this->guard($response);

        /** @var array{data?: array<string, mixed>} $body */
        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        return new BulkStatus(
            bulkEmailId: $bulkEmailId,
            state: is_string($data['state'] ?? null) ? $data['state'] : 'unknown',
            totalRecipients: (int) ($data['total_recipients_count'] ?? 0),
            suppressedCount: (int) ($data['suppressed_recipients_count'] ?? 0),
            validationErrorsCount: (int) ($data['validation_errors_count'] ?? 0),
            validationErrors: is_array($data['validation_errors'] ?? null) ? $data['validation_errors'] : [],
            suppressed: is_array($data['suppressed_recipients'] ?? null) ? $data['suppressed_recipients'] : [],
            messageIds: $this->stringList($data['messages_id'] ?? null),
        );
    }

    /**
     * Reconciliatie na een timeout: bestaan de berichten al?
     *
     * Geeft null terug wanneer het antwoord geen uitsluitsel biedt — dan is
     * niets doen veiliger dan gokken, want een dubbele verzending is erger dan
     * een batch die blijft hangen tot iemand kijkt.
     */
    public function countMessagesWithTag(string $tag): ?int
    {
        try {
            $response = $this->request()->get('/emails', ['tags[]' => $tag, 'limit' => 100]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array{data?: array<int, mixed>} $body */
        $body = $response->json() ?? [];

        return is_array($body['data'] ?? null) ? count($body['data']) : null;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    /**
     * @throws TransportRateLimited
     * @throws TransportRejected
     * @throws TransportUnavailable
     */
    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            throw new TransportRateLimited(
                retryAfter: $this->retryAfter($response),
                quotaExhausted: $this->header($response, 'x-apiquota-remaining') === '0',
            );
        }

        if ($response->status() === 422) {
            /** @var array{message?: string, errors?: array<string, mixed>} $body */
            $body = $response->json() ?? [];

            throw new TransportRejected(
                errors: is_array($body['errors'] ?? null) ? $body['errors'] : [],
                message: is_string($body['message'] ?? null)
                    ? $body['message']
                    : 'MailerSend weigerde de payload.',
            );
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new TransportUnavailable(
                'MailerSend wees de API-sleutel af (HTTP '.$response->status().').'
            );
        }

        throw new TransportUnavailable(
            'Onverwacht antwoord van MailerSend: HTTP '.$response->status()
        );
    }

    private function retryAfter(Response $response): int
    {
        $header = $this->header($response, 'retry-after');

        return is_numeric($header) ? max(1, (int) $header) : self::DEFAULT_RETRY_AFTER;
    }

    private function header(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
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
