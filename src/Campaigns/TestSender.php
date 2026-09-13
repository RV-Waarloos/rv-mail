<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RvWaarloos\RvMail\Contracts\CampaignRenderer;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;

/**
 * Verstuurt één testmail naar de opsteller.
 *
 * Bewust niet via de bulkpijplijn: die maakt batches aan, boekt quotum en zet
 * bestemmelingen op queued. Een testverzending mag de campagne niet aanraken.
 *
 * De placeholders worden hier wél ingevuld, anders krijg je een mail met
 * letterlijk `{{aanspreking}}` erin en heb je niet gezien wat een lid ziet.
 */
final class TestSender
{
    public function __construct(
        private readonly CampaignRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  personalisatie voor de preview
     */
    public function send(Campaign $campaign, string $to, array $overrides = []): void
    {
        $rendered = $this->renderer->render($campaign);
        $data = $this->previewData($campaign, $overrides);

        $html = $this->substitute($rendered->html, $data);
        $subject = $this->substitute($rendered->subject, $data);

        Mail::html($html, function (Message $message) use ($to, $subject, $campaign): void {
            $message->to($to)
                ->subject('[TEST] '.$subject)
                ->from($campaign->from_email, $campaign->from_name);

            if ($campaign->reply_to !== null && $campaign->reply_to !== '') {
                $message->replyTo($campaign->reply_to);
            }
        });
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

            $content = str_replace('{{'.$key.'}}', (string) $value, $content);
        }

        return $content;
    }
}
