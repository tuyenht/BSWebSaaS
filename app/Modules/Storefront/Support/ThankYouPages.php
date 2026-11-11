<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Forms\Models\Form;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Cache;

/**
 * Which pages of the current site are a form's thank-you page (`settings.redirect_entry`, the page's
 * ulid): a page a visitor only reaches after sending a form, which belongs out of search results.
 *
 * The ulids are read from the forms once and kept in the shared cache per site (a page view then costs
 * a cache read, not a query); a form changed in the admin shows up within the time-to-live.
 */
final class ThankYouPages
{
    /** Bump when the SHAPE of the cached value changes. */
    private const string KEY = 'thank-you-pages:v1:';

    private const int TTL = 300;

    /** @var array<int, list<string>> */
    private array $memo = [];

    public function __construct(private readonly CurrentSite $current) {}

    public function has(string $ulid): bool
    {
        if (! $this->current->hasId()) {
            return false;
        }

        return in_array($ulid, $this->ulids($this->current->id()), true);
    }

    /**
     * Every thank-you page of the current site (what sitemap.xml leaves out).
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->current->hasId() ? $this->ulids($this->current->id()) : [];
    }

    /** Drops the cached set of a site (a form's thank-you page changed outside the admin). */
    public static function forget(int $siteId): void
    {
        Cache::forget(self::KEY.$siteId);
    }

    /** @return list<string> */
    private function ulids(int $siteId): array
    {
        return $this->memo[$siteId] ??= Cache::remember(self::KEY.$siteId, self::TTL, static function (): array {
            $ulids = [];
            foreach (Form::query()->where('status', 'active')->get(['id', 'settings']) as $form) {
                $ulid = Cast::arr($form->settings)['redirect_entry'] ?? null;
                if (is_string($ulid) && $ulid !== '') {
                    $ulids[$ulid] = $ulid;
                }
            }

            return array_values($ulids);
        });
    }
}
