<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\MailEventType;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Jobs\ProcessWebhookEvent;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\MailEvent;
use RvWaarloos\RvMail\Models\Suppression;
use RvWaarloos\RvMail\Models\Unsubscribe;
use RvWaarloos\RvMail\Models\WebhookDelivery;
use RvWaarloos\RvMail\Webhooks\SignatureVerifier;

const WEBHOOK_SECRET = 'test-signing-secret';

function webhookCampagne(MailCategory $categorie = MailCategory::Nieuws): Campaign
{
    return Campaign::query()->create([
        'name' => 'Nieuwsbrief',
        'category' => $categorie,
        'subject_template' => 'Clubnieuws',
        'body_markdown' => 'Dag',
        'from_email' => 'info@mail.rvwaarloos.be',
        'from_name' => 'RV Waarloos',
        'audience_type' => 'afdeling',
        'status' => CampaignStatus::Dispatching,
        'composed_at' => Carbon::now(),
    ]);
}

function webhookOntvanger(Campaign $campaign, string $email = 'jan@telenet.be'): CampaignRecipient
{
    return CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'email' => $email,
        'name' => 'Jan Peeters',
        'status' => RecipientStatus::Sent,
        'sent_at' => Carbon::now(),
    ]);
}

/** @return array<string, mixed> */
function webhookPayload(
    Campaign $campaign,
    CampaignRecipient $recipient,
    MailEventType $type = MailEventType::Delivered,
    ?string $eventId = null,
): array {
    return [
        'type' => $type->webhookType(),
        'webhook_id' => 'wh_1',
        'created_at' => Carbon::now()->toIso8601String(),
        'data' => [
            'object' => 'activity',
            'id' => $eventId ?? (string) Str::ulid(),
            'type' => $type->value,
            'created_at' => Carbon::now()->toIso8601String(),
            'email' => [
                'object' => 'email',
                'id' => 'msg_abc',
                'tags' => [...$campaign->tags(), $recipient->tag()],
                'recipient' => ['object' => 'recipient', 'email' => $recipient->email],
            ],
            'morph' => $type === MailEventType::HardBounced
                ? ['reason' => 'Mailbox does not exist', 'bounce_code' => '550']
                : [],
        ],
    ];
}

function postWebhook(array $payload, ?string $signature = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        '/webhooks/mailersend',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGNATURE' => $signature ?? app(SignatureVerifier::class)->sign($body),
        ],
        $body,
    );
}

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('rv-mail.mailersend.webhook_secret', WEBHOOK_SECRET);
    config()->set('rv-mail.webhook.register_route', true);

    $this->app->forgetInstance(SignatureVerifier::class);
    $this->app->instance(SignatureVerifier::class, new SignatureVerifier(WEBHOOK_SECRET));
});

it('weigert een levering zonder geldige handtekening', function (): void {
    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);

    postWebhook(webhookPayload($campaign, $recipient), signature: 'onzin')
        ->assertStatus(401);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('weigert een levering zonder handtekening', function (): void {
    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);

    $body = json_encode(webhookPayload($campaign, $recipient), JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/mailersend', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);
});

it('aanvaardt een geldige levering en zet de verwerking in de wachtrij', function (): void {
    Queue::fake();

    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);

    postWebhook(webhookPayload($campaign, $recipient))->assertStatus(202);

    expect(WebhookDelivery::query()->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('verwerkt dezelfde levering maar een keer', function (): void {
    Queue::fake();

    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);
    $payload = webhookPayload($campaign, $recipient, eventId: 'evt_vast');

    postWebhook($payload)->assertStatus(202);
    postWebhook($payload)->assertStatus(202);

    // MailerSend kan opnieuw proberen; dat mag geen tweede rij en geen tweede
    // job opleveren.
    expect(WebhookDelivery::query()->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('werkt de ontvanger bij op een delivered-event', function (): void {
    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);

    postWebhook(webhookPayload($campaign, $recipient, MailEventType::Delivered))->assertStatus(202);

    $recipient->refresh();

    expect($recipient->status)->toBe(RecipientStatus::Delivered)
        ->and($recipient->delivered_at)->not->toBeNull()
        ->and($recipient->ms_message_id)->toBe('msg_abc')
        ->and($campaign->fresh()->count_delivered)->toBe(1);
});

it('zet een hard bounce meteen op de suppressielijst', function (): void {
    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign, 'bounce@telenet.be');

    postWebhook(webhookPayload($campaign, $recipient, MailEventType::HardBounced))->assertStatus(202);

    expect($recipient->fresh()->status)->toBe(RecipientStatus::HardBounced)
        ->and($recipient->fresh()->failure_reason)->toContain('Mailbox')
        ->and(Suppression::query()->where('email', 'bounce@telenet.be')->exists())->toBeTrue();
});

it('schrijft een uitschrijving weg voor de categorie van de campagne', function (): void {
    $campaign = webhookCampagne(MailCategory::Nieuws);
    $recipient = webhookOntvanger($campaign, 'stop@telenet.be');

    postWebhook(webhookPayload($campaign, $recipient, MailEventType::Unsubscribed))->assertStatus(202);

    $unsubscribe = Unsubscribe::query()->where('email', 'stop@telenet.be')->first();

    // Categoriegebonden: het lid moet de permanentie-oproepen blijven krijgen.
    expect($unsubscribe)->not->toBeNull()
        ->and($unsubscribe->category)->toBe(MailCategory::Nieuws)
        ->and(Suppression::query()->where('email', 'stop@telenet.be')->exists())->toBeFalse();
});

it('schrijft niemand uit voor operationele mail', function (): void {
    $campaign = webhookCampagne(MailCategory::Operationeel);
    $recipient = webhookOntvanger($campaign, 'stop@telenet.be');

    postWebhook(webhookPayload($campaign, $recipient, MailEventType::Unsubscribed))->assertStatus(202);

    // Uitschrijven voor operationele mail bestaat niet; een ruime suppressie
    // zou het lid onbereikbaar maken voor praktische clubzaken.
    expect(Unsubscribe::query()->count())->toBe(0);
});

it('laat een latere sent-event de status niet terugzetten', function (): void {
    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);

    postWebhook(webhookPayload($campaign, $recipient, MailEventType::Delivered))->assertStatus(202);
    postWebhook(webhookPayload($campaign, $recipient, MailEventType::Sent))->assertStatus(202);

    // Webhooks komen niet gegarandeerd in volgorde aan.
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Delivered);
});

it('bewaart een event dat niet aan een ontvanger te koppelen is', function (): void {
    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);

    $payload = webhookPayload($campaign, $recipient);
    $payload['data']['email']['tags'] = [];
    $payload['data']['email']['id'] = 'onbekend';
    $payload['data']['email']['recipient']['email'] = 'vreemde@elders.be';

    postWebhook($payload)->assertStatus(202);

    // Niet herleidbaar, maar wel geregistreerd: anders verlies je het spoor bij
    // mail die buiten dit package om verstuurd is.
    expect(MailEvent::query()->count())->toBe(1)
        ->and(MailEvent::query()->first()->recipient_id)->toBeNull();
});

it('bevestigt de testping zonder iets te verwerken', function (): void {
    postWebhook([
        'type' => 'webhook.test',
        'webhook_id' => 'wh_1',
        'data' => ['id' => 'ping_1'],
    ])->assertStatus(202);

    expect(WebhookDelivery::query()->first()->processed_at)->not->toBeNull()
        ->and(MailEvent::query()->count())->toBe(0);
});

it('legt elk event vast in het eigen log', function (): void {
    $campaign = webhookCampagne();
    $recipient = webhookOntvanger($campaign);

    postWebhook(webhookPayload($campaign, $recipient, MailEventType::Delivered))->assertStatus(202);
    postWebhook(webhookPayload($campaign, $recipient, MailEventType::ClickedUnique))->assertStatus(202);

    // MailerSend bewaart activity-data 24 uur; dit log is de bron van waarheid.
    expect(MailEvent::query()->where('recipient_id', $recipient->id)->count())->toBe(2);
});
