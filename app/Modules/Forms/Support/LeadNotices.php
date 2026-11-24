<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Shared\Support\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The bell's «new lead» notices of leads about to be deleted for good: they carry the visitor's
 * name or phone, so they go with the leads (the daily purges). Looked up through the notices'
 * (site_id, created_at) index from a minute before the first lead to WINDOW_MINUTES after the last,
 * as «Xóa theo yêu cầu của khách» (EraseLead) and DeleteLargeForm do; a set holding a lead without a
 * time is looked up without one. Runs in the current website's context (RLS).
 */
final class LeadNotices
{
    private const int WINDOW_MINUTES = 60;

    /**
     * @param  array<int, object>  $leads  rows with ulid and created_at
     */
    public static function deleteFor(array $leads): void
    {
        if ($leads === []) {
            return;
        }
        $ulids = [];
        $times = [];
        $undated = false;
        foreach ($leads as $lead) {
            $ulids[] = Cast::string($lead->ulid ?? null);
            $at = $lead->created_at ?? null;
            if ($at === null) {
                $undated = true;
            } else {
                $times[] = CarbonImmutable::parse(Cast::string($at));
            }
        }
        $notices = SiteNotification::query()->where('kind', 'lead.new')->whereIn(DB::raw("data->>'submission'"), $ulids);
        if (! $undated) {
            $notices->whereBetween('created_at', [min($times)->subMinute(), max($times)->addMinutes(self::WINDOW_MINUTES)]);
        }
        $notices->delete();
    }
}
