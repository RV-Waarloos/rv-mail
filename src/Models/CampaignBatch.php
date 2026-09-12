<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Enums\BatchState;

/**
 * @property int $id
 * @property string $ulid
 * @property int $campaign_id
 * @property int $sequence
 * @property int $size
 * @property int $payload_bytes
 * @property BatchState $state
 * @property string|null $request_ulid
 * @property string|null $bulk_email_id
 * @property array<int, mixed>|null $validation_errors
 * @property array<int, mixed>|null $suppressed_recipients
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $polled_at
 * @property int $attempts
 * @property Carbon $created_at
 * @property Carbon $updated_at*/
final class CampaignBatch extends Model
{
    use HasUlids;

    protected $table = 'mail_campaign_batches';

    protected $guarded = [];

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => BatchState::class,
            'validation_errors' => 'array',
            'suppressed_recipients' => 'array',
            'scheduled_for' => 'datetime',
            'dispatched_at' => 'datetime',
            'polled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'batch_id');
    }
}
