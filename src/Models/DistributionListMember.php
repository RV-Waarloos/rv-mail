<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ofwel een clublid, ofwel een externe bestemmeling.
 *
 * @property int $id
 * @property int $distribution_list_id
 * @property int|null $member_id
 * @property string|null $email
 * @property string|null $name
 */
final class DistributionListMember extends Model
{
    protected $connection = 'central';

    protected $table = 'mail_distribution_list_members';

    protected $guarded = [];

    /** @return BelongsTo<DistributionList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(DistributionList::class, 'distribution_list_id');
    }
}
