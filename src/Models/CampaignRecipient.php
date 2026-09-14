<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Enums\SkipReason;

/**
 * Eén rij per bestemmeling: het snapshot.
 *
 * Ook overgeslagen kandidaten blijven staan, met een reden. Wie niet bereikt
 * werd is achteraf meestal precies de vraag.
 *
 * @property int $id
 * @property string $ulid
 * @property int $campaign_id
 * @property int|null $batch_id
 * @property int|null $member_id
 * @property string $email
 * @property string|null $name
 * @property array<string, mixed>|null $personalization
 * @property RecipientStatus $status
 * @property SkipReason|null $skip_reason
 * @property string|null $ms_message_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $first_clicked_at
 * @property string|null $failure_reason
 */
final class CampaignRecipient extends Model
{
    use HasUlids;

    protected $connection = 'central';

    protected $table = 'mail_campaign_recipients';

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
            'status' => RecipientStatus::class,
            'skip_reason' => SkipReason::class,
            'personalization' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'first_clicked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /** @return BelongsTo<CampaignBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CampaignBatch::class, 'batch_id');
    }

    /** @return HasMany<MailEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MailEvent::class, 'recipient_id');
    }

    /**
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->where('status', RecipientStatus::Pending);
    }

    /** Correlatiesleutel in de MailerSend-tags; custom headers zijn Professional-only. */
    public function tag(): string
    {
        return 'rcpt:'.$this->ulid;
    }
}
