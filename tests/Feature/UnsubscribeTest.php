<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Mail\UnsubscribeLinkMail;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\MailEvent;
use RvWaarloos\RvMail\Models\Suppression;
use RvWaarloos\RvMail\Models\Unsubscribe;
use RvWaarloos\RvMail\Models\WebhookDelivery;
use RvWaarloos\RvMail\Support\RecipientAnonymizer;

function uitschrijfOntvanger(string $email = 'jan@telenet.be', ?int $memberId = 7): CampaignRecipient
{
    $campaign = Campaign::query()->create([
        'name' => 'Nieuwsbrief',
        'category' => MailCategory::Nieuws,
        'subject_template' => 'Clubnieuws',
        'body_markdown' => 'Dag',
        'from_email' => 'info@mail.rvwaarloos.be',
        'from_name' => 'RV Waarloos',
        'audience_type' => 'afdeling',
        'status' => CampaignStatus::Sent,
        'composed_at' => Carbon::now(),
    ]);

    return CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'member_id' => $memberId,
        'email' => $email,
        'name' => 'Jan Peeters',
        'status' => RecipientStatus::Delivered,
    ]);
}

function uitschrijfUrl(CampaignRecipient $recipient): string
{
    return URL::signedRoute('rv-mail.unsubscribe', ['recipient' => $recipient->ulid]);
}

beforeEach(function (): void {
    config()->set('rv-mail.unsubscribe.register_routes', true);
});

it('toont de voorkeurenpagina via een geldige link', function (): void {
    $recipient = uitschrijfOntvanger();

    $this->get(uitschrijfUrl($recipient))
        ->assertOk()
        ->assertSee('jan@telenet.be')
        ->assertSee('Clubnieuws');
});

it('weigert een link met een gemanipuleerde handtekening', function (): void {
    $recipient = uitschrijfOntvanger();

    $this->get(uitschrijfUrl($recipient).'x')->assertStatus(403);
});

it('blijft werken zonder vervaldatum', function (): void {
    $recipient = uitschrijfOntvanger();
    $url = uitschrijfUrl($recipient);

    // De procedure moet werkelijk eenvoudig zijn, ook vanuit een mail van
    // twee jaar oud.
    $this->travel(2)->years();

    $this->get($url)->assertOk();
});

it('vereist geen login', function (): void {
    $recipient = uitschrijfOntvanger();

    $this->assertGuest();
    $this->get(uitschrijfUrl($recipient))->assertOk();
});

it('schrijft uit voor clubnieuws', function (): void {
    $recipient = uitschrijfOntvanger();

    $this->post(uitschrijfUrl($recipient), ['categories' => []])
        ->assertRedirect();

    $unsubscribe = Unsubscribe::query()->where('email', 'jan@telenet.be')->first();

    expect($unsubscribe)->not->toBeNull()
        ->and($unsubscribe->category)->toBe(MailCategory::Nieuws)
        ->and($unsubscribe->member_id)->toBe(7);
});

it('laat opnieuw inschrijven toe', function (): void {
    $recipient = uitschrijfOntvanger();

    $this->post(uitschrijfUrl($recipient), ['categories' => []]);
    expect(Unsubscribe::query()->count())->toBe(1);

    // Het bezwaarrecht is absoluut, maar geen eenrichtingsverkeer: wie zich
    // bedenkt hoeft niemand te bellen.
    $this->post(uitschrijfUrl($recipient), ['categories' => ['nieuws']]);
    expect(Unsubscribe::query()->count())->toBe(0);
});

it('biedt geen enkele operationele categorie aan om af te zetten', function (): void {
    $recipient = uitschrijfOntvanger();

    $response = $this->get(uitschrijfUrl($recipient));

    $response->assertDontSee('value="operationeel"', escape: false)
        ->assertDontSee('value="permanentie"', escape: false)
        ->assertDontSee('value="transactioneel"', escape: false);
});

it('legt uit dat praktische berichten blijven komen', function (): void {
    $recipient = uitschrijfOntvanger();

    // Zonder die uitleg denkt een lid dat hij alles heeft afgezet en mist hij
    // straks een wedstrijdwijziging.
    $this->get(uitschrijfUrl($recipient))
        ->assertSee('wedstrijdwijzigingen', escape: false);
});

it('stuurt een verse link via het vangnet', function (): void {
    Mail::fake();
    uitschrijfOntvanger('bekend@telenet.be');

    $this->post(route('rv-mail.unsubscribe.send-link'), ['email' => 'bekend@telenet.be'])
        ->assertOk();

    Mail::assertSent(UnsubscribeLinkMail::class);
});

it('verraadt niet of een adres bekend is', function (): void {
    Mail::fake();

    $response = $this->post(route('rv-mail.unsubscribe.send-link'), ['email' => 'onbekend@elders.be']);

    $response->assertOk();
    Mail::assertNothingSent();

    // Dezelfde bevestiging als bij een bekend adres.
    $response->assertSee('binnen enkele minuten', escape: false);
});

it('ruimt verlopen webhookleveringen en events op', function (): void {
    config()->set('rv-mail.retention.webhook_deliveries', 30);
    config()->set('rv-mail.retention.events', 396);

    $recipient = uitschrijfOntvanger();

    WebhookDelivery::query()->create([
        'ms_event_id' => 'oud',
        'type' => 'activity.sent',
        'signature_valid' => true,
        'raw_payload' => [],
        'created_at' => Carbon::now()->subDays(45),
        'updated_at' => Carbon::now()->subDays(45),
    ]);

    WebhookDelivery::query()->create([
        'ms_event_id' => 'recent',
        'type' => 'activity.sent',
        'signature_valid' => true,
        'raw_payload' => [],
    ]);

    MailEvent::query()->create([
        'campaign_id' => $recipient->campaign_id,
        'recipient_id' => $recipient->id,
        'type' => 'sent',
        'occurred_at' => Carbon::now()->subDays(500),
        'received_at' => Carbon::now()->subDays(500),
    ]);

    $this->artisan('rv-mail:purge')->assertSuccessful();

    expect(WebhookDelivery::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->first()->ms_event_id)->toBe('recent')
        ->and(MailEvent::query()->count())->toBe(0)
        // Campagnes blijven: clubcommunicatie is archiefwaardig.
        ->and(Campaign::query()->count())->toBe(1);
});

it('verwijdert niets in dry-run', function (): void {
    WebhookDelivery::query()->create([
        'ms_event_id' => 'oud',
        'type' => 'activity.sent',
        'signature_valid' => true,
        'raw_payload' => [],
        'created_at' => Carbon::now()->subDays(90),
        'updated_at' => Carbon::now()->subDays(90),
    ]);

    $this->artisan('rv-mail:purge --dry-run')->assertSuccessful();

    expect(WebhookDelivery::query()->count())->toBe(1);
});

it('anonimiseert de mailgeschiedenis van een lid zonder rijen te verliezen', function (): void {
    $recipient = uitschrijfOntvanger('jan@telenet.be', memberId: 42);

    Suppression::query()->create([
        'email' => 'jan@telenet.be',
        'reason' => 'hard_bounce',
        'member_id' => 42,
        'suppressed_at' => Carbon::now(),
    ]);

    $count = app(RecipientAnonymizer::class)->forMember(42);

    $recipient->refresh();

    expect($count)->toBe(1)
        // De rij blijft, zodat de statistiek van de campagne blijft kloppen.
        ->and(CampaignRecipient::query()->count())->toBe(1)
        ->and($recipient->member_id)->toBeNull()
        ->and($recipient->name)->toBeNull()
        ->and($recipient->email)->toEndWith('@invalid')
        ->and($recipient->email)->not->toContain('jan')
        ->and(Suppression::query()->count())->toBe(0);
});

it('geeft hetzelfde oude adres dezelfde geanonimiseerde vorm', function (): void {
    $a = uitschrijfOntvanger('gezin@telenet.be', memberId: 1);
    $b = uitschrijfOntvanger('gezin@telenet.be', memberId: 2);

    app(RecipientAnonymizer::class)->forMember(1);
    app(RecipientAnonymizer::class)->forMember(2);

    // Herkenbaar als hetzelfde adres, zonder dat het te achterhalen is.
    expect($a->fresh()->email)->toBe($b->fresh()->email);
});
