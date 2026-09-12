<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;

final class UnknownAudience extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Doelgroep [{$key}] is niet geregistreerd in de AudienceRegistry.");
    }
}
