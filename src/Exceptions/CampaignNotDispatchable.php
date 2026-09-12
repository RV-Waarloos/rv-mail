<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;
use RvWaarloos\RvMail\Enums\CampaignStatus;

final class CampaignNotDispatchable extends RuntimeException
{
    public static function inStatus(CampaignStatus $status): self
    {
        return new self("Een campagne in status [{$status->value}] kan niet verzonden worden.");
    }

    public static function notApproved(): self
    {
        return new self('Deze mailing bereikt alle leden en heeft goedkeuring nodig van iemand anders dan de opsteller.');
    }

    public static function noRecipients(): self
    {
        return new self('Er zijn geen bestemmelingen om naar te verzenden.');
    }

    public static function notComposed(): self
    {
        return new self('De campagne moet eerst samengesteld worden.');
    }
}
