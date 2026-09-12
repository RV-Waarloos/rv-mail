<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

/**
 * Wat MailerSend teruggeeft op een geslaagde bulk-request.
 */
final readonly class BulkDispatchResult
{
    public function __construct(
        public string $bulkEmailId,
        public int $accepted,
    ) {}
}
