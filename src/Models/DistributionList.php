<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $owner_id
 * @property Collection<int, DistributionListMember> $members
 */
final class DistributionList extends Model
{
    protected $table = 'mail_distribution_lists';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<DistributionListMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(DistributionListMember::class, 'distribution_list_id');
    }
}
