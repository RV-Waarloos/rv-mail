<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * De ruwe inbox. De controller doet het minimum — handtekening valideren,
 * payload wegschrijven, 202 teruggeven — en de verwerking gebeurt in een job.
 *
 * @property int $id
 * @property string $ms_event_id
 * @property string $type
 * @property bool $signature_valid
 * @property array<string, mixed> $raw_payload
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
final class WebhookDelivery extends Model
{
    protected $connection = 'central';

    protected $table = 'mail_webhook_deliveries';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
