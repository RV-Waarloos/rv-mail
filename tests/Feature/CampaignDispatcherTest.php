<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RvWaarloos\RvMail\Campaigns\CampaignDispatcher;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Enums\BatchState;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Exceptions\CampaignNotDispatchable;
use RvWaarloos\RvMail\Exceptions\QuotaExceeded;
use RvWaarloos\RvMail\Exceptions\TransportRateLimited;
use RvWaarloos\RvMail\Jobs\SendCampaignBatch;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignBatch;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\QuotaLedgerEntry;
use RvWaarloos\RvMail\Transport\FakeBulkTransport;

function verzendbareCampagne(int $aantal = 5, MailCategory $categorie = MailCategory::Nieuws): Campaign
{
    $campaign = Campaign::query()->create([
        'name' => 'Testmailing',
        'category' => $categorie,
        'subject_template' => 'Clubnieuws',
        'body_markdown' => 'Dag {{aanspreking}}, hier is het nieuws.',
        'from_email' => 'info@mail.rvwaarloos.be',
        'from_name' => 'RV Waarloos',
        'audience_type' => 'afdeling',
        'audience_params' => ['afdeling_id' => 3],
        'status' => CampaignStatus::Composed,
        'composed_at' => Carbon::now(),
    ]);

    for ($i = 1; $i <= $aantal; $i++) {
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => "lid{$i}@telenet.be",
            'name' => "Lid {$i}",
            'personalization' => ['aanspreking' => "Lid {$i}"],
            'status' => RecipientStatus::Pending,
        ]);
    }

    $campaign->forceFill(['recipients_sendable' => $aantal, 'recipients_total' => $aantal])->save();

    return $campaign->refresh();
}

beforeEach(function (): void {
    config()->set('rv-mail.batch.max_messages', 2);
    config()->set('rv-mail.throttle.seconds_between_batches', 8);

    $this->transport = new FakeBulkTransport;
    $this->app->instance(BulkTransport::class, $this->transport);
});

it('verdeelt de bestemmelingen over batches en plant ze gespreid in', function (): void {
    Queue::fake();

    $campaign = verzendbareCampagne(5);

    $count = app(CampaignDispatcher::class)->dispatch($campaign);

    expect($count)->toBe(3)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Dispatching);

    $batches = CampaignBatch::query()->orderBy('sequence')->get();

    // De spreiding zit in absolute tijdstempels, niet in een gedeelde limiter:
    // zo blijft ze correct ongeacht het aantal workers.
    expect($batches->pluck('size')->all())->toBe([2, 2, 1])
        ->and($batches[1]->scheduled_for->diffInSeconds($batches[0]->scheduled_for, absolute: true))
        ->toEqualWithDelta(8, 1);

    Queue::assertPushed(SendCampaignBatch::class, 3);
});

it('markeert bestemmelingen als queued en koppelt ze aan hun batch', function (): void {
    Queue::fake();

    $campaign = verzendbareCampagne(3);
    app(CampaignDispatcher::class)->dispatch($campaign);

    expect(CampaignRecipient::query()->where('status', RecipientStatus::Queued)->count())->toBe(3)
        ->and(CampaignRecipient::query()->whereNull('batch_id')->count())->toBe(0);
});

it('weigert te verzenden wanneer het quotum ontoereikend is', function (): void {
    QuotaLedgerEntry::query()->create([
        'date' => Carbon::now()->toDateString(),
        'emails_sent' => 4_500,
    ]);

    // 5.000 limiet minus 4.500 verbruikt minus 1.000 transactionele reserve
    // laat niets over voor een mailing.
    app(CampaignDispatcher::class)->dispatch(verzendbareCampagne(10));
})->throws(QuotaExceeded::class);

it('weigert een clubbrede mailing zonder goedkeuring', function (): void {
    config()->set('rv-mail.approval.required_for_audiences', ['active_members']);

    $campaign = verzendbareCampagne(3);
    $campaign->forceFill(['audience_type' => 'active_members'])->save();

    app(CampaignDispatcher::class)->dispatch($campaign->fresh());
})->throws(CampaignNotDispatchable::class);

it('weigert een campagne die nog niet samengesteld is', function (): void {
    $campaign = verzendbareCampagne(3);
    $campaign->forceFill(['composed_at' => null])->save();

    app(CampaignDispatcher::class)->dispatch($campaign->fresh());
})->throws(CampaignNotDispatchable::class);

it('verstuurt een batch en boekt het quotum', function (): void {
    $campaign = verzendbareCampagne(2);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $batch = CampaignBatch::query()->first();
    app()->call([new SendCampaignBatch($batch->id), 'handle']);

    expect($this->transport->totalSent())->toBe(2)
        ->and($batch->fresh()->state)->toBe(BatchState::Accepted)
        ->and($batch->fresh()->bulk_email_id)->not->toBeNull()
        ->and(CampaignRecipient::query()->where('status', RecipientStatus::Sent)->count())->toBe(2);

    $ledger = QuotaLedgerEntry::query()->first();
    expect($ledger->emails_sent)->toBe(2)
        ->and($ledger->bulk_requests)->toBe(1);
});

it('verstuurt niets opnieuw wanneer de batch al aanvaard is', function (): void {
    $campaign = verzendbareCampagne(2);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $batch = CampaignBatch::query()->first();
    $job = new SendCampaignBatch($batch->id);

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    // Dubbel uitvoeren van dezelfde job mag nooit tot dubbele mails leiden.
    expect($this->transport->totalSent())->toBe(2);
});

it('zet de batch terug op pending bij een rate limit', function (): void {
    $campaign = verzendbareCampagne(2);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $this->transport->failNextWith(new TransportRateLimited(retryAfter: 42));

    $batch = CampaignBatch::query()->first();
    $job = new SendCampaignBatch($batch->id);
    $job->job = Mockery::mock(Job::class)->shouldIgnoreMissing();

    app()->call([$job, 'handle']);

    expect($batch->fresh()->state)->toBe(BatchState::Pending)
        ->and($this->transport->totalSent())->toBe(0);
});

it('draagt de campagne- en ontvangertags mee in elk bericht', function (): void {
    $campaign = verzendbareCampagne(1);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $batch = CampaignBatch::query()->first();
    app()->call([new SendCampaignBatch($batch->id), 'handle']);

    $message = $this->transport->sentMessages()[0];

    expect($message->tags)->toContain('campaign:'.$campaign->ulid)
        ->and($message->tags)->toContain('rcpt:'.$message->recipientUlid);
});
