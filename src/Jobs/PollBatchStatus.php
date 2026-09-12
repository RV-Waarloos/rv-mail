<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Contracts\SuppressionStore;
use RvWaarloos\RvMail\Enums\BatchState;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Enums\SuppressionReason;
use RvWaarloos\RvMail\Models\CampaignBatch;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Support\QuotaGuard;

/**
 * Haalt de eindstatus van een bulk-request op.
 *
 * MailerSend verwerkt een bulk asynchroon: het 202-antwoord zegt alleen dat de
 * request aanvaard is. Pas hier zie je of er adressen gesuppresseerd of
 * geweigerd zijn.
 */
final class PollBatchStatus implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public function __construct(
        public readonly int $batchId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(BulkTransport $transport, SuppressionStore $suppressions, QuotaGuard $quota): void
    {
        $batch = CampaignBatch::query()->with('campaign')->find($this->batchId);

        if (! $batch instanceof CampaignBatch || $batch->bulk_email_id === null) {
            return;
        }

        if ($batch->state->isTerminal()) {
            return;
        }

        $status = $transport->status($batch->bulk_email_id);
        $quota->record(emails: 0, apiRequests: 1);

        $batch->forceFill(['polled_at' => Carbon::now()])->save();

        if (! $status->isFinished()) {
            $this->release($this->nextDelay());

            return;
        }

        $batch->forceFill([
            'state' => BatchState::Completed,
            'validation_errors' => $status->validationErrors,
            'suppressed_recipients' => $status->suppressed,
        ])->save();

        $this->applySuppressions($batch, $status->suppressed, $suppressions);
        $this->completeCampaignIfDone($batch);
    }

    /**
     * MailerSend weigert adressen die op hun eigen suppressielijst staan. Die
     * nemen we lokaal over, anders proberen we ze bij elke mailing opnieuw.
     *
     * @param  array<int|string, mixed>  $suppressed
     */
    private function applySuppressions(
        CampaignBatch $batch,
        array $suppressed,
        SuppressionStore $suppressions,
    ): void {
        foreach ($suppressed as $entry) {
            $email = is_array($entry) ? ($entry['email'] ?? null) : $entry;

            if (! is_string($email) || $email === '') {
                continue;
            }

            $recipient = CampaignRecipient::query()
                ->where('batch_id', $batch->id)
                ->where('email', mb_strtolower($email))
                ->first();

            $recipient?->forceFill([
                'status' => RecipientStatus::Suppressed,
                'failure_reason' => 'Geweigerd door de suppressielijst van MailerSend.',
            ])->save();

            $suppressions->suppress(
                email: $email,
                reason: SuppressionReason::Invalid,
                memberId: $recipient?->member_id,
                source: 'mailersend-bulk-status',
            );
        }
    }

    /** Alle batches afgerond? Dan is de campagne klaar. */
    private function completeCampaignIfDone(CampaignBatch $batch): void
    {
        $campaign = $batch->campaign;

        if ($campaign === null) {
            return;
        }

        $openBatches = CampaignBatch::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotIn('state', [BatchState::Completed, BatchState::Failed])
            ->exists();

        if ($openBatches) {
            return;
        }

        $anyFailed = CampaignBatch::query()
            ->where('campaign_id', $campaign->id)
            ->where('state', BatchState::Failed)
            ->exists();

        $campaign->forceFill([
            'status' => $anyFailed ? CampaignStatus::Failed : CampaignStatus::Sent,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    private function nextDelay(): int
    {
        $backoff = $this->backoff();

        return $backoff[min($this->attempts() - 1, count($backoff) - 1)] ?? 600;
    }
}
