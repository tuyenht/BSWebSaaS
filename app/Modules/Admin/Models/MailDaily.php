<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A website's mail count for one of its days (its own time zone) and one outbox: sent, failed, and
 * held back to the next day. Tenant table (site_id + RLS). Composite-keyed (site_id, day, via), so it
 * has no id: rows are written only by the atomic upserts of MailQuotaGate and MailLogWriter, read
 * here, and removed only by mail_daily_prune() (the app holds no DELETE right on the table).
 *
 * @property int $site_id
 * @property string $day Y-m-d
 * @property string $via platform|own
 * @property int $sent
 * @property int $failed
 * @property int $held
 */
final class MailDaily extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $table = 'mail_daily';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    public $timestamps = false;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d',
            'sent' => 'integer',
            'failed' => 'integer',
            'held' => 'integer',
        ];
    }
}
