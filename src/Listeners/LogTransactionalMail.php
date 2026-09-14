<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Support\QuotaGuard;
use Symfony\Component\Mime\Address;

/**
 * Logt mail die buiten de campagnepijplijn om verstuurd wordt.
 *
 * Paswoordherstel en wedstrijdnotificaties lopen via de gewone Laravel mailer.
 * Zonder deze listener zie je in het beheerscherm alleen de mailings, en niet
 * dat het paswoordherstel van een lid al drie keer bouncet. Eén overzicht is
 * het hele punt.
 *
 * De bestemmelingen komen onder een pseudo-campagne per maand te staan: een
 * echte campagne aanmaken per transactionele mail zou het overzicht juist
 * onbruikbaar maken.
 */
final class LogTransactionalMail
{
    public function __construct(
        private readonly QuotaGuard $quota,
    ) {}

    public function handle(MessageSent $event): void
    {
        if (config('rv-mail.transactional.log') !== true) {
            return;
        }

        $message = $event->message;
        $recipients = $message->getTo();

        if ($recipients === []) {
            return;
        }

        $campaign = $this->pseudoCampaign();
        $messageId = $this->messageId($event);
        $subject = $message->getSubject();

        foreach ($recipients as $address) {
            $this->record($campaign, $address, $messageId, $subject);
        }

        // Transactionele mail telt mee voor het maandquotum, maar apart, zodat
        // je ziet hoeveel van de 5.000 naar functionele mail gaat.
        $this->quota->record(
            emails: count($recipients),
            transactional: true,
        );
    }

    private function record(Campaign $campaign, Address $address, ?string $messageId, ?string $subject): void
    {
        $email = mb_strtolower($address->getAddress());

        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => $email,
            'name' => $address->getName() !== '' ? $address->getName() : null,
            'personalization' => $subject === null ? null : ['onderwerp' => $subject],
            'status' => RecipientStatus::Sent,
            'ms_message_id' => $messageId,
            'sent_at' => Carbon::now(),
        ]);

        DB::connection('central')->table('mail_campaigns')->where('id', $campaign->id)->increment('count_sent');
    }

    /**
     * Een verzamelcampagne per maand. Dat houdt het beheerscherm leesbaar en
     * maakt de retentie eenvoudig.
     */
    private function pseudoCampaign(): Campaign
    {
        $period = Carbon::now()->format('Y-m');
        $name = 'Transactionele mail '.$period;

        $campaign = Campaign::query()
            ->where('category', MailCategory::Transactioneel)
            ->where('name', $name)
            ->first();

        if ($campaign instanceof Campaign) {
            return $campaign;
        }

        return Campaign::query()->create([
            'name' => $name,
            'category' => MailCategory::Transactioneel,
            'subject_template' => '(wisselend)',
            'body_markdown' => '',
            'from_email' => (string) config('rv-mail.from.address'),
            'from_name' => (string) config('rv-mail.from.name'),
            'audience_type' => 'transactional',
            'status' => CampaignStatus::Dispatching,
            'composed_at' => Carbon::now(),
            'dispatched_at' => Carbon::now(),
        ]);
    }

    /**
     * MailerSend geeft het bericht-id terug in een header. Zonder dat id kan de
     * webhookverwerking het event niet aan deze rij koppelen, maar de fallback
     * op e-mailadres vangt dat meestal op.
     */
    private function messageId(MessageSent $event): ?string
    {
        $header = $event->message->getHeaders()->get('x-message-id')?->getBodyAsString();

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $id = trim($event->sent->getMessageId(), '<>');

        return $id === '' ? null : $id;
    }
}
