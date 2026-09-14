<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Campaigns\TestSender;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\QuotaLedgerEntry;
use RvWaarloos\RvMail\Transport\FakeBulkTransport;

function testCampagne(MailCategory $categorie = MailCategory::Nieuws): Campaign
{
    return Campaign::query()->create([
        'name' => 'Nieuwsbrief',
        'category' => $categorie,
        'subject_template' => 'Clubnieuws voor {{aanspreking}}',
        'body_markdown' => 'Dag {{aanspreking}}, hier is het nieuws.',
        'from_email' => 'info@mail.rvwaarloos.be',
        'from_name' => 'RV Waarloos',
        'reply_to' => 'secretariaat@rvwaarloos.be',
        'audience_type' => 'afdeling',
        'status' => CampaignStatus::Composed,
        'composed_at' => Carbon::now(),
    ]);
}

beforeEach(function (): void {
    $this->transport = new FakeBulkTransport;
    $this->app->instance(BulkTransport::class, $this->transport);
});

it('vult de placeholders in bij een testverzending', function (): void {
    $campaign = testCampagne();

    CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'email' => 'lid@telenet.be',
        'name' => 'Mieke Peeters',
        'personalization' => ['aanspreking' => 'Mieke'],
        'status' => RecipientStatus::Pending,
    ]);

    app(TestSender::class)->send($campaign, 'opsteller@rvwaarloos.be');

    $message = $this->transport->sentMessages()[0];

    // Een test met letterlijk {{aanspreking}} erin laat je niet zien wat een
    // lid ziet, en dat is het hele punt van een testverzending.
    expect($message->html)->not->toContain('{{aanspreking}}')
        ->and($message->html)->toContain('Mieke')
        ->and($message->email)->toBe('opsteller@rvwaarloos.be');
});

it('markeert de testmail als test in het onderwerp', function (): void {
    app(TestSender::class)->send(testCampagne(), 'opsteller@rvwaarloos.be');

    expect($this->transport->sentMessages()[0]->subject)->toStartWith('[TEST]');
});

it('loopt door hetzelfde transport als een echte mailing', function (): void {
    app(TestSender::class)->send(testCampagne(), 'opsteller@rvwaarloos.be');

    // Zo hoeft een app die alleen mailings opstelt geen mailer te configureren,
    // en test je meteen het transport dat straks de echte mailing verstuurt.
    expect($this->transport->totalSent())->toBe(1)
        ->and($this->transport->sentBatches()[0]->isStandalone())->toBeTrue();
});

it('raakt de campagne niet aan bij een testverzending', function (): void {
    $campaign = testCampagne();

    CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'email' => 'lid@telenet.be',
        'status' => RecipientStatus::Pending,
    ]);

    app(TestSender::class)->send($campaign, 'opsteller@rvwaarloos.be');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Composed)
        ->and($campaign->batches()->count())->toBe(0)
        ->and(CampaignRecipient::query()->where('status', RecipientStatus::Pending)->count())->toBe(1);
});

it('boekt de testmail in het quotum', function (): void {
    app(TestSender::class)->send(testCampagne(), 'opsteller@rvwaarloos.be');

    // Een testmail kost een credit; anders loopt de eigen telling scheef
    // tegenover het MailerSend-dashboard.
    expect(QuotaLedgerEntry::query()->first()->emails_sent)->toBe(1);
});

it('valt terug op voorbeeldwaarden zonder bestemmelingen', function (): void {
    app(TestSender::class)->send(testCampagne(), 'opsteller@rvwaarloos.be');

    expect($this->transport->sentMessages()[0]->html)->not->toContain('{{aanspreking}}');
});

it('zet klikregistratie uit op een testverzending', function (): void {
    $campaign = testCampagne();
    $campaign->forceFill(['track_clicks' => true])->save();

    app(TestSender::class)->send($campaign->fresh(), 'opsteller@rvwaarloos.be');

    $payload = $this->transport->sentBatches()[0]->toPayload()[0];

    expect($payload['settings']['track_clicks'])->toBeFalse()
        ->and($payload['settings']['track_opens'])->toBeFalse();
});
