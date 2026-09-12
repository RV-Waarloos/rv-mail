<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;

/**
 * 429 van MailerSend. `retryAfter` komt uit de gelijknamige header;
 * `quotaExhausted` betekent dat het dagelijkse requestquotum op is en wachten
 * tot morgen de enige optie is.
 */
final class TransportRateLimited extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly bool $quotaExhausted = false,
    ) {
        parent::__construct(
            $quotaExhausted
                ? 'Dagelijks API-quotum van MailerSend is uitgeput.'
                : "MailerSend beperkt het tempo; opnieuw proberen over {$retryAfter} seconden."
        );
    }
}
