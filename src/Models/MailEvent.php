<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Enums\MailEventType;

/**
 * Append-only log van wat MailerSend met een bericht deed.
 *
 * Dit is de bron van waarheid: op het Hobby plan verdwijnt de activity-data van
 * MailerSend na 24 uur.
 *
 * @property int $id
 * @property int|null $campaign_id
 * @property int|null $recipient_id
 * @property MailEventType $type
 * @property string|null $ms_message_id
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property array<string, mixed>|null $payload
 */
final class MailEvent extends Model
{
    public $timestamps = false;

    protected $table = 'mail_events';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MailEventType::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'recipient_id');
    }
}
