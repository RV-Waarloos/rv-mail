<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Exceptions;

use RuntimeException;

/**
 * 422 van MailerSend: de payload deugt niet. Opnieuw proberen heeft geen zin,
 * dus de batch gaat definitief op failed.
 */
final class TransportRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'MailerSend weigerde de payload.',
    ) {
        parent::__construct($message);
    }
}
