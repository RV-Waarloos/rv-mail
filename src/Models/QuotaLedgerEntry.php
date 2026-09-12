<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property int $emails_sent
 * @property int $emails_transactional
 * @property int $api_requests
 * @property int $bulk_requests
 */
final class QuotaLedgerEntry extends Model
{
    protected $table = 'mail_quota_ledger';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
