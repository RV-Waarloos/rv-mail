<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Contracts;

use RvWaarloos\RvMail\Exceptions\TransportRateLimited;
use RvWaarloos\RvMail\Exceptions\TransportRejected;
use RvWaarloos\RvMail\Exceptions\TransportUnavailable;
use RvWaarloos\RvMail\Transport\BulkBatch;
use RvWaarloos\RvMail\Transport\BulkDispatchResult;
use RvWaarloos\RvMail\Transport\BulkStatus;

/**
 * Drie implementaties: `mailersend` voor productie, `fake` voor Pest, en later
 * eventueel `smtp` voor een Mailpit-sluis op staging.
 *
 * De smtp-variant ontbindt een batch en stuurt elk bericht apart, maar verzint
 * een bulk_email_id zodat de rest van de pijplijn ongewijzigd blijft werken.
 */
interface BulkTransport
{
    /**
     * @throws TransportRateLimited
     * @throws TransportRejected
     * @throws TransportUnavailable
     */
    public function send(BulkBatch $batch): BulkDispatchResult;

    public function status(string $bulkEmailId): BulkStatus;

    /**
     * Hoeveel berichten met deze tag bestaan er al bij MailerSend?
     *
     * Gebruikt door de reconciliatie: ging een request in timeout, dan weten we
     * zo of hij toch is aangekomen. Moet binnen 24 uur gebeuren, want daarna is
     * de activity-data van het Hobby plan verdwenen.
     */
    public function countMessagesWithTag(string $tag): ?int;
}
