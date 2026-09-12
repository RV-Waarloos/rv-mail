<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RvWaarloos\RvMail\Audiences\AudienceRegistry;
use RvWaarloos\RvMail\Contracts\CampaignRenderer;
use RvWaarloos\RvMail\Enums\BatchState;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Exceptions\AudienceNotAuthorized;
use RvWaarloos\RvMail\Exceptions\CampaignNotDispatchable;
use RvWaarloos\RvMail\Exceptions\QuotaExceeded;
use RvWaarloos\RvMail\Jobs\SendCampaignBatch;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignBatch;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Support\QuotaGuard;
use RvWaarloos\RvMail\Transport\BatchChunker;
use RvWaarloos\RvMail\Transport\BulkMessage;

/**
 * Zet een samengesteld snapshot om in batches en plant die in.
 *
 * De throttling zit niet in een gedeelde rate limiter maar in absolute
 * verzendmomenten: elke batch krijgt een oplopende vertraging mee. Die
 * spreiding blijft daardoor correct ongeacht hoeveel workers er draaien of hoe
 * vaak een container herstart.
 */
final class CampaignDispatcher
{
    private const int RECIPIENT_CHUNK = 500;

    public function __construct(
        private readonly AudienceRegistry $audiences,
        private readonly CampaignRenderer $renderer,
        private readonly BatchChunker $chunker,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * @throws CampaignNotDispatchable
     * @throws AudienceNotAuthorized
     * @throws QuotaExceeded
     */
    public function dispatch(Campaign $campaign, ?Authorizable $actor = null): int
    {
        $this->guard($campaign, $actor);

        $rendered = $this->renderer->render($campaign);
        $from = [
            'address' => $campaign->from_email,
            'name' => $campaign->from_name,
        ];

        $messages = $this->buildMessages($campaign, $rendered);

        if ($messages === []) {
            throw CampaignNotDispatchable::noRecipients();
        }

        $chunks = $this->chunker->chunk($messages, $from, $campaign->reply_to, $campaign->track_clicks);
        $spacing = (int) config('rv-mail.throttle.seconds_between_batches', 8);
        $queue = (string) config('rv-mail.queue', 'mail');

        $batches = DB::transaction(function () use ($campaign, $chunks, $spacing): array {
            $created = [];

            foreach ($chunks as $sequence => $chunk) {
                $scheduledFor = Carbon::now()->addSeconds($sequence * $spacing);

                $batch = CampaignBatch::query()->create([
                    'campaign_id' => $campaign->id,
                    'sequence' => $sequence,
                    'size' => count($chunk['messages']),
                    'payload_bytes' => $chunk['bytes'],
                    'state' => BatchState::Pending,
                    'scheduled_for' => $scheduledFor,
                ]);

                $ids = array_map(
                    static fn (BulkMessage $message): int => $message->recipientId,
                    $chunk['messages'],
                );

                CampaignRecipient::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'batch_id' => $batch->id,
                        'status' => RecipientStatus::Queued,
                    ]);

                $created[] = ['batch' => $batch, 'scheduled_for' => $scheduledFor];
            }

            $campaign->forceFill([
                'status' => CampaignStatus::Dispatching,
                'dispatched_at' => Carbon::now(),
            ])->save();

            return $created;
        });

        // Buiten de transactie dispatchen: anders kan een worker de job
        // oppikken voor de rijen gecommit zijn.
        foreach ($batches as $entry) {
            /** @var CampaignBatch $batch */
            $batch = $entry['batch'];

            SendCampaignBatch::dispatch($batch->id)
                ->onQueue($queue)
                ->delay($entry['scheduled_for']);
        }

        return count($batches);
    }

    /**
     * @throws CampaignNotDispatchable
     * @throws AudienceNotAuthorized
     * @throws QuotaExceeded
     */
    private function guard(Campaign $campaign, ?Authorizable $actor): void
    {
        if (! $campaign->status->canDispatch()) {
            throw CampaignNotDispatchable::inStatus($campaign->status);
        }

        if ($campaign->composed_at === null) {
            throw CampaignNotDispatchable::notComposed();
        }

        // Opnieuw toetsen, ook al gebeurde dat bij het samenstellen: daartussen
        // kan een rol ingetrokken zijn.
        if ($actor instanceof Authorizable) {
            $audience = $this->audiences->get($campaign->audience_type);

            if (! $audience->authorize($actor, $campaign->audience_params ?? [])) {
                throw AudienceNotAuthorized::forAudience($audience->key());
            }
        }

        if ($campaign->requiresApproval() && ! $campaign->isApproved()) {
            throw CampaignNotDispatchable::notApproved();
        }

        $sendable = $campaign->recipients()->sendable()->count();

        if ($sendable === 0) {
            throw CampaignNotDispatchable::noRecipients();
        }

        if (! $this->quota->canSendBulk($sendable)) {
            throw QuotaExceeded::forCampaign($sendable, $this->quota->availableForBulk());
        }
    }

    /**
     * @return list<BulkMessage>
     */
    private function buildMessages(Campaign $campaign, RenderedCampaign $rendered): array
    {
        /** @var list<BulkMessage> $messages */
        $messages = [];

        $campaign->recipients()
            ->sendable()
            ->orderBy('id')
            /** @param Collection<int, CampaignRecipient> $recipients */
            ->chunkById(self::RECIPIENT_CHUNK, function (Collection $recipients) use (&$messages, $campaign, $rendered): void {
                foreach ($recipients as $recipient) {
                    $messages[] = new BulkMessage(
                        recipientId: $recipient->id,
                        recipientUlid: $recipient->ulid,
                        email: $recipient->email,
                        name: $recipient->name,
                        subject: $rendered->subject,
                        html: $rendered->html,
                        text: $rendered->text,
                        personalization: $this->personalizationFor($recipient, $campaign),
                        tags: [...$campaign->tags(), $recipient->tag()],
                    );
                }
            });

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function personalizationFor(CampaignRecipient $recipient, Campaign $campaign): array
    {
        $data = $recipient->personalization ?? [];

        $data['aanspreking'] ??= $this->salutation($recipient);

        if ($campaign->category->requiresUnsubscribeLink()) {
            $data['unsubscribe_url'] = $this->unsubscribeUrl($recipient, $campaign);
        }

        return $data;
    }

    private function salutation(CampaignRecipient $recipient): string
    {
        if ($recipient->name === null || trim($recipient->name) === '') {
            return 'beste';
        }

        $parts = preg_split('/\s+/', trim($recipient->name));

        return $parts === false || $parts === [] ? 'beste' : $parts[0];
    }

    /**
     * Permanent ondertekend en zonder vervaldatum: de uitschrijfprocedure moet
     * werkelijk eenvoudig zijn, ook vanuit een mail van twee jaar oud.
     */
    private function unsubscribeUrl(CampaignRecipient $recipient, Campaign $campaign): string
    {
        $route = (string) config('rv-mail.unsubscribe.route', 'rv-mail.unsubscribe');

        if (! app('router')->has($route)) {
            return '';
        }

        return URL::signedRoute($route, [
            'recipient' => $recipient->ulid,
            'category' => $campaign->category->value,
        ]);
    }
}
