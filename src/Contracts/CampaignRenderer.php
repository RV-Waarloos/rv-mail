<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Contracts;

use RvWaarloos\RvMail\Campaigns\RenderedCampaign;
use RvWaarloos\RvMail\Models\Campaign;

/**
 * Rendert de campagne één keer, met de persoonsgebonden waarden als
 * placeholders die MailerSend per bericht invult.
 *
 * Lokaal renderen in plaats van MailerSend-templates: het Hobby plan staat er
 * maar tien toe, de Templates API kan niet aanmaken, en versiebeheer hoort in
 * git.
 */
interface CampaignRenderer
{
    public function render(Campaign $campaign): RenderedCampaign;
}
