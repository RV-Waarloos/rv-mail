<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;
use RvWaarloos\RvMail\Enums\CampaignStatus;

final class CampaignNotComposable extends RuntimeException
{
    public static function inStatus(CampaignStatus $status): self
    {
        return new self("Een campagne in status [{$status->value}] kan niet (her)samengesteld worden.");
    }
}
