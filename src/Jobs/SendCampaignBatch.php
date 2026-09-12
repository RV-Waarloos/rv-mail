<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Contracts\CampaignRenderer;
use RvWaarloos\RvMail\Enums\BatchState;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Exceptions\TransportRateLimited;
use RvWaarloos\RvMail\Exceptions\TransportRejected;
use RvWaarloos\RvMail\Exceptions\TransportUnavailable;
use RvWaarloos\RvMail\Models\CampaignBatch;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Support\QuotaGuard;
use RvWaarloos\RvMail\Transport\BulkBatch;
use RvWaarloos\RvMail\Transport\BulkMessage;
use Throwable;

/**
 * Verstuurt één batch.
 *
 * De job krijgt een batch-id mee en geen model: een campagne kan uren onderweg
 * zijn, en een geserialiseerd model uit de wachtrij is dan verouderd.
 */
final class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public function __construct(
        public readonly int $batchId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        // Vangnet bovenop de tijdstempel-spreiding: twee workers mogen nooit
        // tegelijk bij MailerSend aankloppen. Werkt op de database lock driver,
        // dus geen Redis nodig.
        return [
            (new WithoutOverlapping('mailersend-bulk'))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(
        BulkTransport $transport,
        CampaignRenderer $renderer,
        QuotaGuard $quota,
    ): void {
        $batch = CampaignBatch::query()->with('campaign')->find($this->batchId);

        if (! $batch instanceof CampaignBatch) {
            return;
        }

        // Al afgerond of definitief mislukt: niets meer te doen. Dit maakt een
        // herhaalde job onschadelijk.
        if ($batch->state->isTerminal() || $batch->state === BatchState::Accepted) {
            return;
        }

        if ($batch->state === BatchState::InFlight && ! $this->safeToRetry($batch, $transport)) {
            // De vorige poging kan de deur uit zijn zonder dat we het antwoord
            // zagen. Dan is blijven hangen beter dan dubbel versturen.
            Log::warning('rv-mail: batch blijft in_flight, wacht op reconciliatie', [
                'batch_id' => $batch->id,
            ]);

            return;
        }

        $requestUlid = (string) Str::ulid();

        $batch->forceFill([
            'state' => BatchState::InFlight,
            'request_ulid' => $requestUlid,
            'attempts' => $batch->attempts + 1,
        ])->save();

        $bulkBatch = $this->buildBulkBatch($batch, $renderer, $requestUlid);

        try {
            $result = $transport->send($bulkBatch);
        } catch (TransportRateLimited $exception) {
            $this->handleRateLimit($batch, $exception);

            return;
        } catch (TransportRejected $exception) {
            $this->handleRejection($batch, $bulkBatch, $exception);

            return;
        } catch (TransportUnavailable $exception) {
            // In_flight laten staan: de reconciliatie beslist of opnieuw
            // proberen veilig is.
            $this->release($this->retryDelay());

            Log::warning('rv-mail: transport niet bereikbaar', [
                'batch_id' => $batch->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $batch->forceFill([
            'state' => BatchState::Accepted,
            'bulk_email_id' => $result->bulkEmailId,
            'dispatched_at' => Carbon::now(),
        ])->save();

        CampaignRecipient::query()
            ->where('batch_id', $batch->id)
            ->where('status', RecipientStatus::Queued)
            ->update(['status' => RecipientStatus::Sent, 'sent_at' => Carbon::now()]);

        $quota->record(
            emails: $result->accepted,
            transactional: $batch->campaign?->category->isTransactional() ?? false,
            apiRequests: 1,
            bulkRequests: 1,
        );

        PollBatchStatus::dispatch($batch->id)
            ->onQueue((string) config('rv-mail.queue', 'mail'))
            ->delay(Carbon::now()->addSeconds(30));
    }

    public function failed(?Throwable $exception): void
    {
        $batch = CampaignBatch::query()->find($this->batchId);

        if (! $batch instanceof CampaignBatch) {
            return;
        }

        $batch->forceFill(['state' => BatchState::Failed])->save();

        CampaignRecipient::query()
            ->where('batch_id', $batch->id)
            ->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Pending])
            ->update([
                'status' => RecipientStatus::Failed,
                'failure_reason' => $exception?->getMessage(),
            ]);
    }

    /**
     * Mag een blijven hangen batch opnieuw verstuurd worden?
     *
     * Alleen als MailerSend bevestigt dat er nog niets met deze tag bestaat.
     * Geeft de API geen uitsluitsel, dan is niets doen veiliger dan gokken:
     * een dubbele mailing is erger dan een batch die op iemand wacht.
     */
    private function safeToRetry(CampaignBatch $batch, BulkTransport $transport): bool
    {
        $campaign = $batch->campaign;

        if ($campaign === null) {
            return false;
        }

        $count = $transport->countMessagesWithTag('campaign:'.$campaign->ulid);

        return $count === 0;
    }

    private function handleRateLimit(CampaignBatch $batch, TransportRateLimited $exception): void
    {
        $batch->forceFill(['state' => BatchState::Pending])->save();

        if ($exception->quotaExhausted) {
            // Dagquotum op: pas na middernacht UTC heeft opnieuw proberen zin.
            $this->release(Carbon::now()->addSeconds(
                (int) Carbon::now()->utc()->diffInSeconds(Carbon::now()->utc()->addDay()->startOfDay())
            ));

            Log::warning('rv-mail: dagelijks API-quotum uitgeput', ['batch_id' => $batch->id]);

            return;
        }

        $this->release($exception->retryAfter);
    }

    private function handleRejection(
        CampaignBatch $batch,
        BulkBatch $bulkBatch,
        TransportRejected $exception,
    ): void {
        $batch->forceFill([
            'state' => BatchState::Failed,
            'validation_errors' => $exception->errors,
        ])->save();

        CampaignRecipient::query()
            ->whereIn('id', $bulkBatch->recipientIds())
            ->update([
                'status' => RecipientStatus::Failed,
                'failure_reason' => $exception->getMessage(),
            ]);

        // Opnieuw proberen heeft geen zin bij een 422.
        $this->fail($exception);
    }

    private function retryDelay(): int
    {
        $backoff = $this->backoff();

        return $backoff[min($this->attempts() - 1, count($backoff) - 1)] ?? 300;
    }

    private function buildBulkBatch(
        CampaignBatch $batch,
        CampaignRenderer $renderer,
        string $requestUlid,
    ): BulkBatch {
        $campaign = $batch->campaign;

        if ($campaign === null) {
            throw new TransportUnavailable('Batch zonder campagne.');
        }

        $rendered = $renderer->render($campaign);

        /** @var list<BulkMessage> $messages */
        $messages = [];

        $recipients = CampaignRecipient::query()
            ->where('batch_id', $batch->id)
            ->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Pending])
            ->orderBy('id')
            ->get();

        foreach ($recipients as $recipient) {
            $messages[] = new BulkMessage(
                recipientId: $recipient->id,
                recipientUlid: $recipient->ulid,
                email: $recipient->email,
                name: $recipient->name,
                subject: $rendered->subject,
                html: $rendered->html,
                text: $rendered->text,
                personalization: $recipient->personalization ?? [],
                tags: [...$campaign->tags(), $recipient->tag()],
            );
        }

        return new BulkBatch(
            record: $batch,
            messages: $messages,
            from: ['address' => $campaign->from_email, 'name' => $campaign->from_name],
            replyTo: $campaign->reply_to,
            trackClicks: $campaign->track_clicks,
            requestUlid: $requestUlid,
        );
    }
}
