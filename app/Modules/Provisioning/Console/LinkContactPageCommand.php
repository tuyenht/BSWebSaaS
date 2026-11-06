<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\Actions\LinkContactPage;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Records, for sites made before the contact page was a setting, which page is their contact page:
 * the published page whose route in the site's default language is /lien-he. It writes only where
 * the setting is missing, or names a page that no longer exists on the site (a trashed page still
 * counts as existing); it never replaces one that points at a live page, so a second run changes
 * nothing. A site with no such page is reported and left alone. Each site runs inside its own tenant context;
 * a failure on one is reported and the walk goes on. Give --site (the site's id) for one site.
 */
final class LinkContactPageCommand extends Command
{
    #[\Override]
    protected $signature = 'site:link-contact-page {--site= : Only this site (its id); default every site}';

    #[\Override]
    protected $description = 'Set the contact page (the target of the product quote button) for sites that lack it.';

    public function handle(Tenancy $tenancy, LinkContactPage $link): int
    {
        $only = $this->option('site');
        if ($only !== null && ! ctype_digit($only)) {
            $this->error('--site must be a site id.');

            return self::FAILURE;
        }

        if ($only !== null && ! Site::query()->whereKey((int) $only)->exists()) {
            $this->error("No site with id {$only}.");

            return self::FAILURE;
        }

        $failed = 0;
        $ids = $only !== null ? [(int) $only] : $tenancy->siteIds();

        foreach ($ids as $siteId) {
            try {
                $result = $tenancy->enter($siteId, fn (): string => $this->linkSite($link));
            } catch (Throwable $e) {
                $failed++;
                $result = 'failed: '.$e->getMessage();
            }
            $this->line("site {$siteId}: {$result}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function linkSite(LinkContactPage $link): string
    {
        $locale = SiteLocale::query()->where('is_default', true)->value('locale');
        $ulid = SiteRoute::query()
            ->where('locale', is_string($locale) ? $locale : 'vi')
            ->where('path', '/lien-he')
            ->whereHas('entry', fn (Builder $entry) => $entry->where('status', 'published'))
            ->with('entry:id,ulid')
            ->first()?->entry?->ulid;

        if (! is_string($ulid)) {
            return 'no contact page';
        }

        return match ($link->handle($ulid)) {
            LinkContactPage::LINKED => 'linked',
            LinkContactPage::RELINKED => 'relinked (old page gone)',
            default => 'already set',
        };
    }
}
