<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Enums\SuppressionReason;

/**
 * @property int $id
 * @property string $email
 * @property SuppressionReason $reason
 * @property int|null $member_id
 * @property string|null $source
 * @property Carbon $suppressed_at
 * @property string|null $note
 */
final class Suppression extends Model
{
    protected $connection = 'central';

    protected $table = 'mail_suppressions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
            'suppressed_at' => 'datetime',
        ];
    }
}
