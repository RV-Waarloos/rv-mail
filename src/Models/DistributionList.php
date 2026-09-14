<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property int|null $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Collection<int, DistributionListMember> $members
 */
final class DistributionList extends Model
{
    protected $connection = 'central';

    protected $table = 'mail_distribution_lists';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return HasMany<DistributionListMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(DistributionListMember::class, 'distribution_list_id');
    }

    protected static function booted(): void
    {
        self::creating(static function (self $list): void {
            if ($list->owner_id !== null) {
                return;
            }

            $id = auth()->id();

            $list->owner_id = is_numeric($id) ? (int) $id : null;
        });
    }
}
