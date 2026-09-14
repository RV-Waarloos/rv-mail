<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Enums\MailCategory;

/**
 * Granulaire uitschrijving per categorie: het clubnieuws afzetten zonder de
 * permanentie-oproepen te missen.
 *
 * @property int $id
 * @property int|null $member_id
 * @property string $email
 * @property MailCategory $category
 * @property string|null $source
 * @property Carbon $unsubscribed_at
 */
final class Unsubscribe extends Model
{
    protected $connection = 'central';

    protected $table = 'mail_unsubscribes';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => MailCategory::class,
            'unsubscribed_at' => 'datetime',
        ];
    }
}
