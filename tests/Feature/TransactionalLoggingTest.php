<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\QuotaLedgerEntry;

beforeEach(function (): void {
    config()->set('rv-mail.transactional.log', true);
    config()->set('mail.default', 'array');
});

it('logt een transactionele mail onder een pseudo-campagne', function (): void {
    Mail::raw('Je nieuwe paswoord', function ($message): void {
        $message->to('jan@telenet.be', 'Jan Peeters')->subject('Paswoord herstellen');
    });

    $campaign = Campaign::query()->where('category', MailCategory::Transactioneel)->first();

    expect($campaign)->not->toBeNull()
        ->and($campaign->name)->toContain('Transactionele mail')
        ->and(CampaignRecipient::query()->where('email', 'jan@telenet.be')->exists())->toBeTrue();
});

it('bundelt transactionele mail per maand in plaats van per bericht', function (): void {
    foreach (['a@telenet.be', 'b@telenet.be', 'c@telenet.be'] as $address) {
        Mail::raw('Bericht', fn ($message) => $message->to($address)->subject('Test'));
    }

    // Een campagne per transactionele mail zou het overzicht onbruikbaar maken.
    expect(Campaign::query()->where('category', MailCategory::Transactioneel)->count())->toBe(1)
        ->and(CampaignRecipient::query()->count())->toBe(3);
});

it('boekt transactionele mail apart in het quotum', function (): void {
    Mail::raw('Bericht', fn ($message) => $message->to('jan@telenet.be')->subject('Test'));

    $ledger = QuotaLedgerEntry::query()->first();

    // Zo zie je hoeveel van de 5.000 naar functionele mail gaat.
    expect($ledger->emails_sent)->toBe(1)
        ->and($ledger->emails_transactional)->toBe(1);
});

it('logt niets wanneer de optie uitstaat', function (): void {
    config()->set('rv-mail.transactional.log', false);

    Mail::raw('Bericht', fn ($message) => $message->to('jan@telenet.be')->subject('Test'));

    expect(Campaign::query()->count())->toBe(0);
});
