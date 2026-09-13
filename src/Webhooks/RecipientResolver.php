<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Webhooks;

use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;

/**
 * Zoekt de juiste ontvangerrij bij een binnenkomend event.
 *
 * Drie sleutels, in volgorde van betrouwbaarheid. De eerste is de bedoeling;
 * de andere twee zijn vangnetten voor het geval tags wegvallen, bijvoorbeeld
 * bij mail die niet via dit package verstuurd is.
 */
final class RecipientResolver
{
    public function resolve(WebhookPayload $payload): ?CampaignRecipient
    {
        return $this->byRecipientTag($payload)
            ?? $this->byMessageId($payload)
            ?? $this->byEmailWithinCampaign($payload);
    }

    /** De bedoelde weg: rcpt:<ulid> uit de tags. */
    private function byRecipientTag(WebhookPayload $payload): ?CampaignRecipient
    {
        $ulid = $payload->recipientUlid();

        if ($ulid === null) {
            return null;
        }

        return CampaignRecipient::query()->where('ulid', $ulid)->first();
    }

    /** Tweede sleutel: het bericht-id dat we bij de verzending opsloegen. */
    private function byMessageId(WebhookPayload $payload): ?CampaignRecipient
    {
        if ($payload->messageId === null) {
            return null;
        }

        return CampaignRecipient::query()
            ->where('ms_message_id', $payload->messageId)
            ->first();
    }

    /**
     * Laatste redmiddel: adres binnen de campagne. Onbetrouwbaar bij gezinnen
     * die een adres delen, want dan zijn er meerdere rijen met hetzelfde adres.
     * Daarom alleen wanneer er precies een is.
     */
    private function byEmailWithinCampaign(WebhookPayload $payload): ?CampaignRecipient
    {
        $campaignUlid = $payload->campaignUlid();

        if ($campaignUlid === null || $payload->recipientEmail === null) {
            return null;
        }

        $campaign = Campaign::query()->where('ulid', $campaignUlid)->first();

        if (! $campaign instanceof Campaign) {
            return null;
        }

        $matches = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('email', mb_strtolower($payload->recipientEmail))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
