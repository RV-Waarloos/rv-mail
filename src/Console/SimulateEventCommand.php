<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RvWaarloos\RvMail\Enums\MailEventType;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Webhooks\SignatureVerifier;

/**
 * Stuurt een correct ondertekende payload naar het eigen endpoint.
 *
 * Bewust via HTTP en niet rechtstreeks naar de job: zo lopen de
 * handtekeningcontrole, de idempotentie en de volledige verwerking mee. Events
 * rechtstreeks in mail_events schrijven zou precies die drie overslaan, en dat
 * zijn de plekken waar fouten stil blijven.
 */
final class SimulateEventCommand extends Command
{
    protected $signature = 'rv-mail:simulate-event
        {--campaign= : ULID van de campagne}
        {--type=delivered : Eventsoort}
        {--limit=1 : Aantal bestemmelingen}
        {--all : Alle bestemmelingen van de campagne}
        {--url= : Endpoint, standaard de eigen route}';

    protected $description = 'Simuleert MailerSend-webhookevents tegen het eigen endpoint';

    public function handle(SignatureVerifier $verifier): int
    {
        if (app()->environment('production')) {
            $this->components->error('Dit commando is niet bedoeld voor productie.');

            return self::FAILURE;
        }

        $typeOption = $this->option('type');
        $eventType = is_string($typeOption) ? MailEventType::tryFrom($typeOption) : null;

        if (! $eventType instanceof MailEventType) {
            $this->components->error('Onbekende eventsoort. Kies uit: '.implode(', ', array_map(
                static fn (MailEventType $case): string => $case->value,
                MailEventType::cases(),
            )));

            return self::FAILURE;
        }

        $campaign = $this->resolveCampaign();

        if (! $campaign instanceof Campaign) {
            $this->components->error('Campagne niet gevonden.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 1;

        $recipients = $campaign->recipients()
            ->when(
                ! $this->option('all'),
                fn (Builder $query): Builder => $query->limit($limit),
            )
            ->get();

        if ($recipients->isEmpty()) {
            $this->components->warn('Deze campagne heeft geen bestemmelingen.');

            return self::SUCCESS;
        }

        $urlOption = $this->option('url');
        $url = is_string($urlOption) && $urlOption !== ''
            ? $urlOption
            : route('rv-mail.webhook');
        $sent = 0;

        foreach ($recipients as $recipient) {
            $body = json_encode($this->payload($campaign, $recipient, $eventType), JSON_THROW_ON_ERROR);

            $response = Http::withHeaders(['Signature' => $verifier->sign($body)])
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                $sent++;
            } else {
                $this->components->warn("Geweigerd voor {$recipient->email}: HTTP {$response->status()}");
            }
        }

        $this->components->info("{$sent} event(s) van type {$eventType->value} verstuurd.");

        return self::SUCCESS;
    }

    private function resolveCampaign(): ?Campaign
    {
        $ulid = $this->option('campaign');

        if (is_string($ulid) && $ulid !== '') {
            return Campaign::query()->where('ulid', $ulid)->first();
        }

        return Campaign::query()->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Campaign $campaign, CampaignRecipient $recipient, MailEventType $type): array
    {
        $morph = match ($type) {
            MailEventType::HardBounced => ['object' => 'recipient_bounce', 'reason' => 'Mailbox does not exist', 'bounce_code' => '550'],
            MailEventType::SoftBounced => ['object' => 'recipient_bounce', 'reason' => 'Mailbox full', 'bounce_code' => '421'],
            MailEventType::ClickedUnique => ['object' => 'click', 'url' => 'https://rvwaarloos.be/kalender'],
            MailEventType::SpamComplaint => ['object' => 'spam_complaint'],
            default => [],
        };

        return [
            'type' => $type->webhookType(),
            'domain_id' => 'simulated',
            'webhook_id' => 'simulated',
            'created_at' => Carbon::now()->toIso8601String(),
            'data' => [
                'object' => 'activity',
                'id' => (string) Str::ulid(),
                'type' => $type->value,
                'created_at' => Carbon::now()->toIso8601String(),
                'email' => [
                    'object' => 'email',
                    'id' => $recipient->ms_message_id ?? 'sim_'.Str::lower((string) Str::ulid()),
                    'created_at' => Carbon::now()->toIso8601String(),
                    'from' => $campaign->from_email,
                    'subject' => $campaign->subject_template,
                    'status' => $type->value,
                    'tags' => [...$campaign->tags(), $recipient->tag()],
                    'recipient' => [
                        'object' => 'recipient',
                        'id' => $recipient->ulid,
                        'email' => $recipient->email,
                        'created_at' => Carbon::now()->toIso8601String(),
                    ],
                ],
                'morph' => $morph,
            ],
        ];
    }
}
