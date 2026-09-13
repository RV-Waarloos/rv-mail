<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RvWaarloos\RvMail\Campaigns\TestSender;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use Symfony\Component\Mime\Email;

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
    config()->set('mail.default', 'array');
    config()->set('rv-mail.transactional.log', false);
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

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    $email = $sent[0]->getOriginalMessage();

    expect($email)->toBeInstanceOf(Email::class);

    $body = (string) $email->getHtmlBody();

    // Een test met letterlijk {{aanspreking}} erin laat je niet zien wat een
    // lid ziet, en dat is het hele punt van een testverzending.
    expect($body)->not->toContain('{{aanspreking}}')
        ->and($body)->toContain('Mieke');
});

it('markeert de testmail als test in het onderwerp', function (): void {
    app(TestSender::class)->send(testCampagne(), 'opsteller@rvwaarloos.be');

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    expect($sent[0]->getOriginalMessage()->getSubject())->toStartWith('[TEST]');
});

it('raakt de campagne niet aan bij een testverzending', function (): void {
    Mail::fake();

    $campaign = testCampagne();

    CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'email' => 'lid@telenet.be',
        'status' => RecipientStatus::Pending,
    ]);

    app(TestSender::class)->send($campaign, 'opsteller@rvwaarloos.be');

    // Geen batches, geen statuswijziging, geen quotum op de campagne.
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Composed)
        ->and($campaign->batches()->count())->toBe(0)
        ->and(CampaignRecipient::query()->where('status', RecipientStatus::Pending)->count())->toBe(1);
});

it('valt terug op voorbeeldwaarden zonder bestemmelingen', function (): void {
    app(TestSender::class)->send(testCampagne(), 'opsteller@rvwaarloos.be');

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    $html = (string) $sent[0]->getOriginalMessage()->getHtmlBody();
    preg_match_all('/\{\{[^}]+\}\}/', $html, $m);
    dump($m[0]);

    expect($html)->not->toContain('{{aanspreking}}');
});
