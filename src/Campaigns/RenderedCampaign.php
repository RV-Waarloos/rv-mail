<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

/**
 * De campagne, één keer gerenderd. De persoonsgebonden waarden staan er nog als
 * placeholder in en worden door MailerSend per bericht ingevuld.
 */
final readonly class RenderedCampaign
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {}

    public function approximateBytes(): int
    {
        return strlen($this->html) + strlen($this->text) + strlen($this->subject);
    }
}
