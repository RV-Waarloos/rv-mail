<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

use Illuminate\Support\Str;
use RvWaarloos\RvMail\Contracts\CampaignRenderer;
use RvWaarloos\RvMail\Models\Campaign;

/**
 * Rendert de body als Markdown in een Blade-layout.
 *
 * De placeholders van MailerSend blijven staan: die gebruiken een accolade-
 * syntax die Markdown ongemoeid laat, dus ze overleven de conversie zonder
 * escaping.
 */
final class BladeCampaignRenderer implements CampaignRenderer
{
    public function render(Campaign $campaign): RenderedCampaign
    {
        $body = Str::markdown($campaign->body_markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        /** @var view-string $view */
        $view = 'rv-mail::layouts.campaign';

        $html = (string) view($view, [
            'campaign' => $campaign,
            'body' => $body,
            'showUnsubscribe' => $campaign->category->requiresUnsubscribeLink(),
        ])->render();

        return new RenderedCampaign(
            subject: $campaign->subject_template,
            html: $html,
            text: $this->toPlainText($campaign),
        );
    }

    /**
     * De tekstversie vertrekt van de Markdown en niet van de HTML: strip_tags
     * op een opgemaakte mail levert een onleesbare brij op, terwijl Markdown al
     * bedoeld is om als platte tekst leesbaar te zijn.
     */
    private function toPlainText(Campaign $campaign): string
    {
        $text = trim($campaign->body_markdown);

        if ($campaign->category->requiresUnsubscribeLink()) {
            $text .= "\n\n---\n".trans('rv-mail::mail.unsubscribe_text');
        }

        return $text;
    }
}
