<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RvWaarloos\RvMail\Contracts\BulkTransport;

/**
 * Houdt alles bij in het geheugen en verstuurt niets.
 *
 * Twee gebruikers met verschillende noden. In tests wil je assertions en
 * stilte; in RV_MAIL_DRY_RUN wil je juist zien wat er zou zijn vertrokken,
 * anders is de modus onbruikbaar om de opmaak bij te schaven.
 *
 * Vandaar dat het loggen aan de configuratie hangt en niet standaard aanstaat:
 * de testsuite hoeft geen berg debugregels te produceren.
 */
final class FakeBulkTransport implements BulkTransport
{
    /** @var list<BulkBatch> */
    private array $sent = [];

    /** @var array<string, BulkStatus> */
    private array $statuses = [];

    /** @var list<\Throwable> */
    private array $failures = [];

    /** @var array<string, int> */
    private array $tagCounts = [];

    private ?bool $logging = null;

    /**
     * De eerstvolgende send() gooit deze exception. Handig om 429- en
     * 422-paden te testen zonder het netwerk aan te raken.
     */
    public function failNextWith(\Throwable $exception): self
    {
        $this->failures[] = $exception;

        return $this;
    }

    public function answerStatusWith(string $bulkEmailId, BulkStatus $status): self
    {
        $this->statuses[$bulkEmailId] = $status;

        return $this;
    }

    public function answerTagCount(string $tag, int $count): self
    {
        $this->tagCounts[$tag] = $count;

        return $this;
    }

    /** Expliciet aan- of uitzetten, bijvoorbeeld in een test die het log nakijkt. */
    public function withLogging(bool $enabled = true): self
    {
        $this->logging = $enabled;

        return $this;
    }

    public function send(BulkBatch $batch): BulkDispatchResult
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        $this->sent[] = $batch;

        $bulkEmailId = 'bulk_'.Str::lower((string) Str::ulid());

        $this->log($batch, $bulkEmailId);

        return new BulkDispatchResult(
            bulkEmailId: $bulkEmailId,
            accepted: $batch->size(),
        );
    }

    public function status(string $bulkEmailId): BulkStatus
    {
        return $this->statuses[$bulkEmailId] ?? new BulkStatus(
            bulkEmailId: $bulkEmailId,
            state: 'completed',
            totalRecipients: 0,
            suppressedCount: 0,
            validationErrorsCount: 0,
        );
    }

    public function countMessagesWithTag(string $tag): ?int
    {
        return $this->tagCounts[$tag] ?? null;
    }

    /**
     * Schrijft de volledige HTML per bericht weg.
     *
     * Zonder de body is de dry-run-modus alleen bruikbaar om te controleren
     * dát er iets zou vertrekken, en niet wat. Juist dat laatste is waarvoor je
     * hem lokaal aanzet.
     */
    private function log(BulkBatch $batch, string $bulkEmailId): void
    {
        if (! $this->shouldLog()) {
            return;
        }

        Log::channel((string) config('rv-mail.mailersend.log_channel') ?: config('logging.default'))
            ->debug('rv-mail dry-run: batch niet verstuurd', [
                'bulk_email_id' => $bulkEmailId,
                'berichten' => $batch->size(),
                'van' => $batch->from['address'],
                'antwoord_naar' => $batch->replyTo,
                'losse_verzending' => $batch->isStandalone(),
            ]);

        foreach ($batch->messages as $message) {
            Log::debug('rv-mail dry-run: bericht', [
                'naar' => $message->email,
                'onderwerp' => $message->subject,
                'tags' => $message->tags,
                'personalisatie' => $message->personalization,
                'html' => $message->html,
                'tekst' => $message->text,
            ]);
        }
    }

    private function shouldLog(): bool
    {
        if ($this->logging !== null) {
            return $this->logging;
        }

        // In de testsuite standaard uit: honderd tests die elk een volledige
        // HTML-body wegschrijven maakt het log onleesbaar.
        if (app()->runningUnitTests()) {
            return false;
        }

        return config('rv-mail.mailersend.dry_run') === true;
    }

    /** @return list<BulkBatch> */
    public function sentBatches(): array
    {
        return $this->sent;
    }

    /** @return list<BulkMessage> */
    public function sentMessages(): array
    {
        if ($this->sent === []) {
            return [];
        }

        return array_merge(...array_map(
            static fn (BulkBatch $batch): array => $batch->messages,
            $this->sent,
        ));
    }

    public function totalSent(): int
    {
        return array_sum(array_map(
            static fn (BulkBatch $batch): int => $batch->size(),
            $this->sent,
        ));
    }

    public function assertSentTo(string $email): void
    {
        $addresses = array_map(
            static fn (BulkMessage $message): string => $message->email,
            $this->sentMessages(),
        );

        Assert::assertContains($email, $addresses, "Er is geen bericht verstuurd naar {$email}.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Er zijn onverwacht berichten verstuurd.');
    }

    public function assertBatchCount(int $expected): void
    {
        Assert::assertCount($expected, $this->sent, 'Onverwacht aantal batches.');
    }
}
