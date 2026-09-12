<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;

final class AudienceNotAuthorized extends RuntimeException
{
    public static function forAudience(string $key): self
    {
        return new self("Deze gebruiker mag doelgroep [{$key}] niet aanspreken met deze parameters.");
    }
}
