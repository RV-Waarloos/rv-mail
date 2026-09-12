<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;

final class QuotaExceeded extends RuntimeException
{
    public static function forCampaign(int $needed, int $available): self
    {
        return new self(
            "Deze mailing heeft {$needed} mails nodig, maar er zijn er nog {$available} beschikbaar ".
            'binnen het plan (na aftrek van de transactionele reserve).',
        );
    }
}
