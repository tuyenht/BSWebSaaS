<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's tally of one contact button of a website: how many times visitors tapped `channel`
 * where it is drawn (`placement`) on the website's calendar `day`. Written only by
 * `contact:flush-clicks` (one upsert per website), read by ContactClickStats.
 *
 * @property int $id
 * @property int $site_id
 * @property CarbonImmutable $day
 * @property string $channel
 * @property string $placement
 * @property int $clicks
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ContactClickDaily extends Model
{
    use BelongsToSite;

    /** Where a contact button is drawn: the round buttons, the phone bar, the speed dial, the bottom sheet. */
    public const array PLACEMENTS = ['floating', 'bar', 'dial', 'sheet'];

    #[\Override]
    protected $table = 'contact_click_daily';

    #[\Override]
    protected $guarded = [];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['day' => 'immutable_date', 'clicks' => 'integer'];
    }
}
