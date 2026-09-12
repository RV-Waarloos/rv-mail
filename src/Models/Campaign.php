<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\DedupStrategy;
use RvWaarloos\RvMail\Enums\MailCategory;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property MailCategory $category
 * @property string $subject_template
 * @property string $body_markdown
 * @property string $from_email
 * @property string $from_name
 * @property string|null $reply_to
 * @property string $audience_type
 * @property array<string, mixed>|null $audience_params
 * @property DedupStrategy $dedup_strategy
 * @property CampaignStatus $status
 * @property bool $track_clicks
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $composed_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $completed_at
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int $recipients_total
 * @property int $recipients_sendable
 * @property int $count_sent
 * @property int $count_delivered
 * @property int $count_clicked
 * @property int $count_soft_bounced
 * @property int $count_hard_bounced
 * @property int $count_complained
 * @property int $count_suppressed
 * @property int $count_failed
 */
final class Campaign extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;

    protected $table = 'mail_campaigns';

    protected $guarded = [];

    /**
     * Het auditspoor van wie wat wanneer deed loopt via
     * owen-it/laravel-auditing, hetzelfde package als rv-core. mail_events gaat
     * over wat MailerSend met een bericht deed; die twee mogen niet door elkaar.
     *
     * @var array<int, string>
     */
    protected array $auditInclude = [
        'name',
        'category',
        'subject_template',
        'body_markdown',
        'audience_type',
        'audience_params',
        'dedup_strategy',
        'status',
        'scheduled_at',
        'approved_by',
        'approved_at',
    ];

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => MailCategory::class,
            'status' => CampaignStatus::class,
            'dedup_strategy' => DedupStrategy::class,
            'audience_params' => 'array',
            'track_clicks' => 'boolean',
            'scheduled_at' => 'datetime',
            'composed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }

    /** @return HasMany<CampaignBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(CampaignBatch::class, 'campaign_id');
    }

    /** @return HasMany<MailEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MailEvent::class, 'campaign_id');
    }

    /**
     * Een tweede paar ogen is vereist bij een clubbrede mailing, en optioneel
     * vanaf een drempel die standaard uitstaat.
     */
    public function requiresApproval(): bool
    {
        /** @var list<string> $audiences */
        $audiences = config('rv-mail.approval.required_for_audiences', []);

        if (in_array($this->audience_type, $audiences, true)) {
            return true;
        }

        $threshold = config('rv-mail.approval.required_from_recipients');

        return is_int($threshold) && $this->recipients_sendable >= $threshold;
    }

    /**
     * Wie send én approve heeft, mag niet zijn eigen clubbrede mailing
     * goedkeuren — anders zijn de vier ogen er twee.
     */
    public function canBeApprovedBy(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        if (config('rv-mail.approval.approver_must_differ', true) !== true) {
            return true;
        }

        return $this->created_by !== $userId;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Correlatiesleutels voor MailerSend. Custom headers zijn Professional-only,
     * dus tags zijn de enige betrouwbare weg terug van een webhook-event naar
     * de juiste campagne.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['campaign:'.$this->ulid, 'cat:'.$this->category->value];
    }
}
