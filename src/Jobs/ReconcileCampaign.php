<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Enums\BatchState;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignBatch;

/**
 * Zoekt batches die in_flight bleven hangen en beslist wat ermee moet.
 *
 * Draait elk uur, en dat is geen willekeurige frequentie: op het Hobby plan
 * bewaart MailerSend activity-data 24 uur. Daarna kun je niet meer achterhalen
 * of een request is aangekomen, en blijft alleen handmatig nakijken over.
 */
final class ReconcileCampaign implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Hoelang een batch onderweg mag zijn voor we hem verdacht vinden. */
    private const int STALE_MINUTES = 10;

    public function __construct(
        public readonly ?int $campaignId = null,
    ) {}

    public function handle(BulkTransport $transport): void
    {
        $query = CampaignBatch::query()
            ->with('campaign')
            ->where('state', BatchState::InFlight)
            ->where('updated_at', '<', Carbon::now()->subMinutes(self::STALE_MINUTES));

        if ($this->campaignId !== null) {
            $query->where('campaign_id', $this->campaignId);
        }

        foreach ($query->cursor() as $batch) {
            $this->reconcile($batch, $transport);
        }
    }

    private function reconcile(CampaignBatch $batch, BulkTransport $transport): void
    {
        $campaign = $batch->campaign;

        if (! $campaign instanceof Campaign) {
            return;
        }

        $count = $transport->countMessagesWithTag('campaign:'.$campaign->ulid);

        if ($count === null) {
            Log::warning('rv-mail: reconciliatie zonder uitsluitsel', [
                'batch_id' => $batch->id,
                'campaign' => $campaign->ulid,
            ]);

            return;
        }

        if ($count === 0) {
            // Niets aangekomen: veilig om opnieuw in te plannen.
            $batch->forceFill(['state' => BatchState::Pending])->save();

            SendCampaignBatch::dispatch($batch->id)
                ->onQueue((string) config('rv-mail.queue', 'mail'));

            return;
        }

        // Er bestaan berichten met deze tag. Opnieuw versturen zou dubbel zijn;
        // laat de webhooks de rest afhandelen.
        $batch->forceFill([
            'state' => BatchState::Accepted,
            'dispatched_at' => $batch->dispatched_at ?? Carbon::now(),
        ])->save();

        Log::info('rv-mail: batch bleek toch aangekomen', [
            'batch_id' => $batch->id,
            'messages_found' => $count,
        ]);
    }
}
