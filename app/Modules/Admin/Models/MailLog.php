<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One mail a website sent or failed to send («Thư gần đây»). Tenant table (site_id + RLS), split by
 * month (UTC) and append-only for the app: written only by MailLogWriter, never updated, removed a
 * whole month at a time by `mail:prune`. Holds no body, subject or server answer. Never addressed
 * from outside: the list pages by (created_at, id).
 *
 * @property int $id
 * @property int $site_id
 * @property string|null $notification_id
 * @property string $kind one of MailKinds::ALL
 * @property string $via platform|own
 * @property int $recipient_count
 * @property string|null $to_first
 * @property string $status sent|failed
 * @property string|null $reason_code one of MailReason::CODES when failed
 * @property Carbon $created_at
 */
final class MailLog extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'recipient_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
