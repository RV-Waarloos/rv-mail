<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Netwerkfout of 5xx. Hier is opnieuw proberen wél zinvol, maar alleen na een
 * reconciliatie: de request kan de deur uit zijn zonder dat we het antwoord
 * zagen.
 */
final class TransportUnavailable extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
