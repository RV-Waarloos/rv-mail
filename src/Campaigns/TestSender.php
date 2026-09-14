<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use Illuminate\Support\Str;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Contracts\CampaignRenderer;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Support\QuotaGuard;
use RvWaarloos\RvMail\Transport\BulkBatch;
use RvWaarloos\RvMail\Transport\BulkMessage;

/**
 * Verstuurt één testmail naar de opsteller.
 *
 * Loopt via hetzelfde BulkTransport als de campagnepijplijn, niet via de
 * Laravel mailer. Dat scheelt een mailconfiguratie in elke app die alleen
 * mailings opstelt maar ze niet zelf verstuurt, en het test meteen het transport
 * dat straks ook de echte mailing verstuurt.
 *
 * De campagne blijft onaangeroerd: geen batchrij, geen statuswijziging, geen
 * bestemmelingen die op queued gaan.
 */
final class TestSender
{
    public function __construct(
        private readonly CampaignRenderer $renderer,
        private readonly BulkTransport $transport,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  personalisatie voor de preview
     */
    public function send(Campaign $campaign, string $to, array $overrides = []): void
    {
        $rendered = $this->renderer->render($campaign);
        $data = $this->previewData($campaign, $overrides);

        $message = new BulkMessage(
            // Geen id: deze verzending hoort bij geen enkele bestemmeling.
            recipientId: 0,
            recipientUlid: 'test',
            email: $to,
            name: null,
            subject: '[TEST] ' . $this->substitute($rendered->subject, $data),
            html: $this->substitute($rendered->html, $data),
            text: $this->substitute($rendered->text, $data),
            // Personalisatie is hier al ingevuld: een test met letterlijk
            // {{aanspreking}} erin laat niet zien wat een lid ziet.
            personalization: [],
            tags: [...$campaign->tags(), 'test:1'],
        );

        $this->transport->send(new BulkBatch(
            record: null,
            messages: [$message],
            from: ['address' => $campaign->from_email, 'name' => $campaign->from_name],
            replyTo: $campaign->reply_to ?? (string) config('rv-mail.reply_to'),
            trackClicks: false,
            requestUlid: (string) Str::ulid(),
        ));

        // Een testmail kost een credit, dus hij hoort in het ledger. Anders
        // loopt de eigen telling scheef tegenover het MailerSend-dashboard.
        $this->quota->record(emails: 1, transactional: true, apiRequests: 1);
    }

    /**
     * Neemt de personalisatie van een echte bestemmeling wanneer die er is.
     * Dan zie je de mail zoals een lid hem krijgt, en niet een variant met
     * verzonnen waarden die net anders uitpakt.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function previewData(Campaign $campaign, array $overrides): array
    {
        $sample = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('personalization')
            ->first();

        $defaults = [
            'aanspreking' => 'Jan',
            'voornaam' => 'Jan',
            'achternaam' => 'Peeters',
            'afdeling' => 'U15',
            'lidnummer' => '0000',
            'unsubscribe_url' => '#voorbeeld-uitschrijflink',
        ];

        return array_merge($defaults, $sample->personalization ?? [], $overrides);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function substitute(string $content, array $data): string
    {
        foreach ($data as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return $content;
    }
}
