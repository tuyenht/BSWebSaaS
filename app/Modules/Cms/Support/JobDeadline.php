<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;

/**
 * Where a job post stands against its application deadline: open, closing soon, today, expired,
 * or no date. The rule belongs to the core, so a theme only draws the state it is handed; a theme
 * with no job layout shows such posts as ordinary ones.
 */
final class JobDeadline
{
    public const string OPEN = 'open';

    public const string CLOSING_SOON = 'closing_soon';

    public const string TODAY = 'today';

    public const string EXPIRED = 'expired';

    public const string NO_DATE = 'no_date';

    /** A deadline within this many days (counting from today) is "closing soon". */
    public const int CLOSING_SOON_DAYS = 7;

    /** The chip labels that mark an application deadline, in vi, en, ja and zh. */
    private const string LABELS = 'Hạn nộp(?: hồ sơ)?|Application deadline|Deadline|応募締切|応募締め切り|締切|截止日期|申请截止|截止';

    /**
     * The last day applications are taken, as a day in the site's time zone; null for a post with
     * none or one whose chip does not read as a date. The deadline is a job fact chip, "Hạn nộp:
     * dd/mm/yyyy" (d/m/yyyy too), in `meta.facts`; it is never a column. Never throws.
     */
    public function deadlineOf(Entry $post, string $zone = SiteTime::DEFAULT_ZONE): ?CarbonImmutable
    {
        $meta = $post->meta;
        $facts = is_array($meta['facts'] ?? null) ? $meta['facts'] : [];
        foreach ($facts as $fact) {
            $texts = is_array($fact) && is_array($fact['text'] ?? null) ? $fact['text'] : [];
            foreach ($texts as $text) {
                if (! is_string($text)) {
                    continue;
                }
                if (preg_match('~^\s*(?:'.self::LABELS.')\s*[:：]\s*(\d{1,2})/(\d{1,2})/(\d{4})\s*$~u', $text, $m) !== 1) {
                    continue;
                }
                [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
                if (! checkdate($month, $day, $year)) {
                    continue;
                }
                try {
                    return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $zone);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * The state of a deadline seen on $today (both in the site's time zone; only the day counts).
     *
     * @return self::OPEN|self::CLOSING_SOON|self::TODAY|self::EXPIRED|self::NO_DATE
     */
    public function state(?CarbonImmutable $deadline, CarbonImmutable $today): string
    {
        if ($deadline === null) {
            return self::NO_DATE;
        }

        $days = (int) $today->startOfDay()->diffInDays($deadline->startOfDay(), false);

        return match (true) {
            $days < 0 => self::EXPIRED,
            $days === 0 => self::TODAY,
            $days <= self::CLOSING_SOON_DAYS => self::CLOSING_SOON,
            default => self::OPEN,
        };
    }
}
