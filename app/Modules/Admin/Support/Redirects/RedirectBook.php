<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Redirects;

use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The changes the «Chuyển hướng» screen makes to the current site's redirects: add or edit one,
 * move rows to the trash and back, delete one from the trash for good, and switch rows between
 * «Chuyển hẳn» (301) and «Chuyển tạm» (302). The caller checks the right (settings.manage) first.
 *
 * Every row is found by its key (ulid) through the Redirect model, so the site scope and RLS keep
 * another site's rows out of reach: a key of another site is simply not found.
 *
 * Addresses are kept as the storefront compares them: the part after the domain, starting with "/",
 * without the language prefix (the storefront strips it before the lookup and lays it over the new
 * address). The old address is lower-cased, without a trailing "/", a query or an anchor (the
 * storefront looks up the lower-cased path alone); non-ASCII letters are percent-encoded as browsers
 * send them. Only an address on this website is accepted as the new one (SafeLink::isSitePath): a
 * redirect to another website is not offered.
 */
final class RedirectBook
{
    /** How far a chain is followed when looking for a loop. */
    private const int CHAIN_MAX = 10;

    /** The columns' width. */
    public const int PATH_MAX = 255;

    public function __construct(private readonly SiteLocale $locales) {}

    /**
     * The old address as the storefront looks it up; null when it is not an address of this site.
     *
     * @param  list<string>  $hosts  the site's own host names (any "www." is ignored)
     */
    public function oldPath(string $raw, string $locale, array $hosts): ?string
    {
        $path = $this->sitePath($raw, $locale, $hosts);
        if ($path === null) {
            return null;
        }
        $path = (string) preg_replace('/[?#].*\z/s', '', $path);
        $path = mb_strtolower($path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * The new address as it is stored; null when it is not an address of this site.
     *
     * @param  list<string>  $hosts
     */
    public function newPath(string $raw, string $locale, array $hosts): ?string
    {
        return $this->sitePath($raw, $locale, $hosts);
    }

    /**
     * What is wrong with a redirect as entered, field by field (empty when it can be saved).
     *
     * @param  list<string>  $hosts
     * @return array<'from'|'to', string>
     */
    public function problems(?string $ulid, string $locale, string $fromRaw, string $toRaw, array $hosts): array
    {
        $errors = [];
        $from = $this->oldPath($fromRaw, $locale, $hosts);
        $to = $this->newPath($toRaw, $locale, $hosts);

        if (trim($fromRaw) === '') {
            $errors['from'] = __('admin.rd.err_from_empty');
        } elseif ($from === null || ! SafeLink::isSitePath($from) || strlen($from) > self::PATH_MAX) {
            $errors['from'] = __('admin.rd.err_from_site');
        }
        if (trim($toRaw) === '') {
            $errors['to'] = __('admin.rd.err_to_empty');
        } elseif ($to === null || ! SafeLink::isSitePath($to) || strlen($to) > self::PATH_MAX) {
            $errors['to'] = __('admin.rd.err_to_site');
        }
        if ($errors !== [] || $from === null || $to === null) {
            return $errors;
        }

        $selfId = $ulid === null ? null : $this->find($ulid, false)?->id;
        if ($from === $this->oldPath($to, $locale, $hosts)) {
            return ['to' => __('admin.rd.err_same')];
        }

        $taken = Redirect::query()->where('locale', $locale)->where('from_path', $from)
            ->when($selfId !== null, fn ($q) => $q->whereKeyNot($selfId))->first(['to_path']);
        if ($taken !== null) {
            return ['from' => __('admin.rd.err_taken', ['to' => $taken->to_path])];
        }

        $chain = $this->loopThrough($locale, $from, $to, $selfId, $hosts);
        if ($chain !== null) {
            return ['to' => __('admin.rd.err_loop', ['chain' => implode(' → ', $chain)])];
        }

        return [];
    }

    /** Whether the old address is a live address of the site in that language (the content wins over the redirect). */
    public function isLiveAddress(string $locale, string $from): bool
    {
        return SiteRoute::query()->where('locale', $locale)->where('path', $from)->exists();
    }

    /**
     * Add a redirect (ulid null) or change one in service. Returns the saved row, or null when the
     * row is gone or its old address was taken meanwhile.
     *
     * @param  list<string>  $hosts
     */
    public function save(?string $ulid, string $locale, string $fromRaw, string $toRaw, int $code, array $hosts): ?Redirect
    {
        $from = (string) $this->oldPath($fromRaw, $locale, $hosts);
        $to = (string) $this->newPath($toRaw, $locale, $hosts);
        $code = in_array($code, Redirect::STATUS_CODES, true) ? $code : 301;

        try {
            return DB::transaction(function () use ($ulid, $locale, $from, $to, $code): ?Redirect {
                if ($ulid === null) {
                    return Redirect::query()->create([
                        'locale' => $locale, 'from_path' => $from, 'to_path' => $to,
                        'status_code' => $code, 'source' => Redirect::SOURCE_MANUAL,
                    ]);
                }
                $row = $this->find($ulid, false);
                if ($row === null) {
                    return null;
                }
                $row->update(['locale' => $locale, 'from_path' => $from, 'to_path' => $to, 'status_code' => $code]);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Move rows in service to the trash.
     *
     * @param  list<string>  $ulids
     * @return list<string> the keys moved
     */
    public function trash(array $ulids): array
    {
        $rows = $this->many($ulids, false);
        if ($rows->isEmpty()) {
            return [];
        }
        Redirect::query()->whereKey($rows->modelKeys())->delete();

        return array_values(array_filter($rows->pluck('ulid')->all(), 'is_string'));
    }

    /**
     * Bring rows back from the trash. A row whose old address another row in service holds now
     * stays in the trash (two rows may not share it).
     *
     * @param  list<string>  $ulids
     * @return array{restored: int, blocked: int}
     */
    public function restore(array $ulids): array
    {
        $rows = $this->many($ulids, true);
        $restored = $blocked = 0;
        foreach ($rows as $row) {
            $taken = Redirect::query()->where('locale', $row->locale)->where('from_path', $row->from_path)->exists();
            if ($taken) {
                $blocked++;

                continue;
            }
            try {
                DB::transaction(fn () => $row->restore());
                $restored++;
            } catch (UniqueConstraintViolationException) {
                $blocked++;
            }
        }

        return ['restored' => $restored, 'blocked' => $blocked];
    }

    /** Delete a row in the trash for good; false when there is no such row in the trash. */
    public function purge(string $ulid): bool
    {
        $row = $this->find($ulid, true);
        if ($row === null) {
            return false;
        }

        return (bool) $row->forceDelete();
    }

    /**
     * Switch rows in service to a kind; rows already of that kind are left as they are.
     *
     * @param  list<string>  $ulids
     * @return int the rows changed
     */
    public function setCode(array $ulids, int $code): int
    {
        if (! in_array($code, Redirect::STATUS_CODES, true)) {
            return 0;
        }
        $ids = $this->many($ulids, false)->where('status_code', '!=', $code)->modelKeys();

        return $ids === [] ? 0 : Redirect::query()->whereKey($ids)->update(['status_code' => $code]);
    }

    /** The row with this key: in service ($trashed false) or in the trash ($trashed true). */
    public function find(string $ulid, bool $trashed): ?Redirect
    {
        if (! Str::isUlid($ulid)) {
            return null;
        }

        return ($trashed ? Redirect::onlyTrashed() : Redirect::query())->where('ulid', strtoupper($ulid))->first();
    }

    /**
     * @param  list<string>  $ulids
     * @return Collection<int, Redirect>
     */
    public function many(array $ulids, bool $trashed): Collection
    {
        $keys = array_values(array_unique(array_map('strtoupper', array_filter($ulids, fn (string $u): bool => Str::isUlid($u)))));
        if ($keys === []) {
            return new Collection;
        }

        return ($trashed ? Redirect::onlyTrashed() : Redirect::query())->whereIn('ulid', $keys)->orderBy('id')->get();
    }

    /**
     * The chain back to $from when following the redirects in service from $to leads there
     * (a → b → a), else null. The row being edited ($self) is left out: its new values are the
     * ones being checked.
     *
     * @param  list<string>  $hosts
     * @return list<string>|null
     */
    private function loopThrough(string $locale, string $from, string $to, ?int $self, array $hosts): ?array
    {
        $chain = [$from, $to];
        $at = $this->oldPath($to, $locale, $hosts);
        for ($hop = 0; $hop < self::CHAIN_MAX && $at !== null; $hop++) {
            $next = Redirect::query()->where('locale', $locale)->where('from_path', $at)
                ->when($self !== null, fn ($q) => $q->whereKeyNot($self))->value('to_path');
            if (! is_string($next)) {
                return null;
            }
            $chain[] = $next;
            $at = $this->oldPath($next, $locale, $hosts);
            if ($at === $from) {
                return $chain;
            }
        }

        return null;
    }

    /**
     * What a person typed or pasted, as a path of this site in the row's language: spaces trimmed
     * (inner ones become "-"), the site's own scheme and host taken off, a leading "/" added, the
     * language prefix of a non-default language taken off, non-ASCII letters percent-encoded.
     * Null for an address on another website.
     *
     * @param  list<string>  $hosts
     */
    private function sitePath(string $raw, string $locale, array $hosts): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        $value = (string) preg_replace('/\s+/u', '-', $value);

        if (preg_match('~\A(?:https?:)?//([^/?#]+)(.*)\z~is', $value, $m) === 1) {
            if (! self::isHostOf($m[1], $hosts)) {
                return null;
            }
            $value = $m[2] === '' ? '/' : $m[2];
        } elseif (preg_match('~\A[a-z][a-z0-9+.-]*:~i', $value) === 1) {
            return null;
        } elseif (preg_match('~\A([^/?#]+)(?:[/?#]|\z)~', $value, $m) === 1 && self::isHostOf($m[1], $hosts)) {
            // "tencongty.vn/abc" pasted without "https://": the site's own host, then the path
            // ("gioi-thieu.html" alone is a path: only the site's own host names count)
            return $this->sitePath('//'.$value, $locale, $hosts);
        }

        $value = '/'.ltrim($value, '/');
        if ($locale !== $this->locales->default()) {
            $prefix = '/'.$locale;
            if (strcasecmp($value, $prefix) === 0) {
                $value = '/';
            } elseif (str_starts_with(strtolower($value), $prefix.'/')) {
                $value = substr($value, strlen($prefix));
            }
        }

        return (string) preg_replace_callback('/[^\x21-\x7E]/u', static fn (array $c): string => rawurlencode($c[0]), $value);
    }

    /** @param  list<string>  $hosts */
    private static function isHostOf(string $host, array $hosts): bool
    {
        return in_array(self::bareHost($host), array_map(static fn (string $h): string => self::bareHost($h), $hosts), true);
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower((string) preg_replace('/:\d+\z/', '', $host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
