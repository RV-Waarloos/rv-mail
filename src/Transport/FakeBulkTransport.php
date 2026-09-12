<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RvWaarloos\RvMail\Contracts\BulkTransport;

/**
 * Houdt alles bij in het geheugen en verstuurt niets.
 *
 * Wordt gebruikt door de tests en door RV_MAIL_DRY_RUN, zodat je lokaal de
 * volledige pijplijn kunt draaien zonder credits te verbranden.
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

    public function send(BulkBatch $batch): BulkDispatchResult
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        $this->sent[] = $batch;

        return new BulkDispatchResult(
            bulkEmailId: 'bulk_'.Str::lower((string) Str::ulid()),
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

    /** @return list<BulkBatch> */
    public function sentBatches(): array
    {
        return $this->sent;
    }

    /** @return list<BulkMessage> */
    public function sentMessages(): array
    {
        return array_merge(...array_map(
            static fn (BulkBatch $batch): array => $batch->messages,
            $this->sent,
        )) ?: [];
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
