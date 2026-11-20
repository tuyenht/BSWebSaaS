<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Redirects;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RedirectRules;
use App\Modules\Shared\Support\Cast;
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
 * send them.
 *
 * The new address is a path on this website (SafeLink::isSitePath) — or, only when the caller says
 * the website may ($external: the member holds the right and the website's switch is on), another
 * website's address, stored exactly as SafeLink::externalHttps() gives it. An old address under a
 * path the application's own routes use (sign-in, /admin…: RedirectRules::reservedFrom) is refused.
 * Every change that makes, alters or removes a redirect to another website leaves an audit row
 * (who, from, to) in the same transaction: the change does not happen unaudited.
 */
final class RedirectBook
{
    /** How far a chain is followed when looking for a loop. */
    private const int CHAIN_MAX = 10;

    /** The columns' width. */
    public const int PATH_MAX = 255;

    public const string AUDIT_ADDED = 'cms.redirect.external_added';

    public const string AUDIT_CHANGED = 'cms.redirect.external_changed';

    public const string AUDIT_TRASHED = 'cms.redirect.external_trashed';

    public const string AUDIT_RESTORED = 'cms.redirect.external_restored';

    public const string AUDIT_PURGED = Redirect::AUDIT_EXTERNAL_PURGED;

    public function __construct(
        private readonly SiteLocale $locales,
        private readonly RedirectRules $rules,
    ) {}

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
     * The new address as it is stored: a path of this site, or with $external another website's
     * address; null when it is neither (or too long for the column).
     *
     * @param  list<string>  $hosts
     */
    public function target(string $raw, string $locale, array $hosts, bool $external): ?string
    {
        $path = $this->newPath($raw, $locale, $hosts);
        if ($path !== null && SafeLink::isSitePath($path) && strlen($path) <= self::PATH_MAX) {
            return $path;
        }
        $url = $external && $path === null ? SafeLink::externalHttps($raw) : null;

        return $url !== null && strlen($url) <= self::PATH_MAX ? $url : null;
    }

    /**
     * What is wrong with a redirect as entered, field by field (empty when it can be saved).
     *
     * @param  list<string>  $hosts
     * @param  bool  $external  whether another website's address may be the new one
     * @return array<'from'|'to', string>
     */
    public function problems(?string $ulid, string $locale, string $fromRaw, string $toRaw, array $hosts, bool $external = false): array
    {
        $errors = [];
        $from = $this->oldPath($fromRaw, $locale, $hosts);
        $to = $this->target($toRaw, $locale, $hosts, $external);

        if (trim($fromRaw) === '') {
            $errors['from'] = __('admin.rd.err_from_empty');
        } elseif ($from === null || ! SafeLink::isSitePath($from) || strlen($from) > self::PATH_MAX) {
            $errors['from'] = __('admin.rd.err_from_site');
        } elseif ($this->rules->reservedFrom($from)) {
            $errors['from'] = __('admin.rd.err_from_system');
        }
        if (trim($toRaw) === '') {
            $errors['to'] = __('admin.rd.err_to_empty');
        } elseif ($to === null) {
            $errors['to'] = match (true) {
                strlen(trim($toRaw)) > self::PATH_MAX => __('admin.rd.err_to_long', ['max' => self::PATH_MAX]),
                $external && $this->newPath($toRaw, $locale, $hosts) === null => __('admin.rd.err_to_external'),
                $external => __('admin.rd.err_to_either'),
                default => __('admin.rd.err_to_site'),
            };
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
     * The caller checks problems() first, with the same $external.
     *
     * @param  list<string>  $hosts
     */
    public function save(?string $ulid, string $locale, string $fromRaw, string $toRaw, int $code, array $hosts, bool $external = false): ?Redirect
    {
        $from = $this->oldPath($fromRaw, $locale, $hosts);
        $to = $this->target($toRaw, $locale, $hosts, $external);
        $code = in_array($code, Redirect::STATUS_CODES, true) ? $code : 301;
        if ($from === null || $to === null || $this->rules->reservedFrom($from)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($ulid, $locale, $from, $to, $code): ?Redirect {
                if ($ulid === null) {
                    $row = Redirect::query()->create([
                        'locale' => $locale, 'from_path' => $from, 'to_path' => $to,
                        'status_code' => $code, 'source' => Redirect::SOURCE_MANUAL,
                    ]);
                    if (RedirectRules::isExternal($row)) {
                        $this->audit(self::AUDIT_ADDED, $row, null);
                    }

                    return $row;
                }
                // read locked: whether the row led to another website is decided on what is changed
                $row = $this->find($ulid, false, lock: true);
                if ($row === null) {
                    return null;
                }
                $was = $row->only(['locale', 'from_path', 'to_path', 'status_code']);
                $wasExternal = RedirectRules::isExternal($row);
                $row->update(['locale' => $locale, 'from_path' => $from, 'to_path' => $to, 'status_code' => $code]
                    // a new destination no longer leads to the content the system wrote the row for (the
                    // row stays one the system made: source says how it began)
                    + ($to !== $row->to_path ? ['entry_id' => null, 'product_id' => null, 'taxonomy_id' => null] : []));
                if ($wasExternal || RedirectRules::isExternal($row)) {
                    $this->audit(self::AUDIT_CHANGED, $row, $was);
                }

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
        // the rows are read locked in the transaction, so the audit follows what is moved, not an
        // earlier read that an edit in between has made stale
        return DB::transaction(function () use ($ulids): array {
            $rows = $this->many($ulids, false, lock: true);
            if ($rows->isEmpty()) {
                return [];
            }
            Redirect::query()->whereKey($rows->modelKeys())->delete();
            foreach ($rows->filter(fn (Redirect $row): bool => RedirectRules::isExternal($row)) as $row) {
                $this->audit(self::AUDIT_TRASHED, $row, null);
            }

            return array_values(array_filter($rows->pluck('ulid')->all(), 'is_string'));
        });
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
        $restored = $blocked = 0;
        foreach ($this->many($ulids, true) as $listed) {
            try {
                // read again locked in the transaction: a row restored or deleted for good meanwhile
                // is left alone, and the audit follows the row as it is brought back
                $done = DB::transaction(function () use ($listed): ?bool {
                    $row = Redirect::onlyTrashed()->whereKey($listed->getKey())->lockForUpdate()->first();
                    if ($row === null) {
                        return null;
                    }
                    if (Redirect::query()->where('locale', $row->locale)->where('from_path', $row->from_path)->exists()) {
                        return false;
                    }
                    $row->restore();
                    if (RedirectRules::isExternal($row)) {
                        $this->audit(self::AUDIT_RESTORED, $row, null);
                    }

                    return true;
                });
            } catch (UniqueConstraintViolationException) {
                $done = false;
            }
            if ($done === true) {
                $restored++;
            } elseif ($done === false) {
                $blocked++;
            }
        }

        return ['restored' => $restored, 'blocked' => $blocked];
    }

    /** Delete a row in the trash for good; false when there is no such row in the trash. */
    public function purge(string $ulid): bool
    {
        // read locked in the transaction: a row brought back from the trash meanwhile is never deleted
        return DB::transaction(function () use ($ulid): bool {
            $row = $this->find($ulid, true, lock: true);
            if ($row === null) {
                return false;
            }
            $gone = (bool) $row->forceDelete();
            if ($gone && RedirectRules::isExternal($row)) {
                $this->audit(self::AUDIT_PURGED, $row, null);
            }

            return $gone;
        });
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

        // read locked in the transaction, like trash()
        return DB::transaction(function () use ($ulids, $code): int {
            $rows = $this->many($ulids, false, lock: true)->where('status_code', '!=', $code);
            if ($rows->isEmpty()) {
                return 0;
            }
            $n = Redirect::query()->whereKey($rows->modelKeys())->update(['status_code' => $code]);
            foreach ($rows->filter(fn (Redirect $row): bool => RedirectRules::isExternal($row)) as $row) {
                $was = $row->only(['locale', 'from_path', 'to_path', 'status_code']);
                $row->status_code = $code;
                $this->audit(self::AUDIT_CHANGED, $row, $was);
            }

            return $n;
        });
    }

    /** The row with this key: in service ($trashed false) or in the trash ($trashed true); $lock reads it FOR UPDATE. */
    public function find(string $ulid, bool $trashed, bool $lock = false): ?Redirect
    {
        if (! Str::isUlid($ulid)) {
            return null;
        }

        return ($trashed ? Redirect::onlyTrashed() : Redirect::query())->where('ulid', strtoupper($ulid))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /**
     * @param  list<string>  $ulids
     * @return Collection<int, Redirect>
     */
    public function many(array $ulids, bool $trashed, bool $lock = false): Collection
    {
        $keys = array_values(array_unique(array_map('strtoupper', array_filter($ulids, fn (string $u): bool => Str::isUlid($u)))));
        if ($keys === []) {
            return new Collection;
        }

        return ($trashed ? Redirect::onlyTrashed() : Redirect::query())->whereIn('ulid', $keys)->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    /**
     * The audit row of a change to a redirect to another website (or from one, on an edit): who (the
     * signed-in member, filled in by the audit service), from and to. Written in the change's own
     * transaction and never swallowed: if it cannot be written, the change rolls back.
     *
     * @param  self::AUDIT_*  $action
     * @param  array<string, mixed>|null  $was  the row's values before an edit
     */
    private function audit(string $action, Redirect $row, ?array $was): void
    {
        $removed = in_array($action, [self::AUDIT_TRASHED, self::AUDIT_PURGED], true);
        Audit::recordOrFail($action, [
            'auditable_type' => Redirect::class,
            'auditable_id' => $row->getKey(),
            'meta' => array_filter([
                'name' => $row->from_path,
                'was' => $removed ? $row->to_path : ($was === null ? null : Cast::string($was['to_path'] ?? null)),
                'value' => $removed ? null : $row->to_path,
                'ulid' => $row->ulid,
                'locale' => $row->locale,
                'from' => $row->from_path,
                'to' => $row->to_path,
                'status_code' => $row->status_code,
                'before' => $was,
            ], fn (mixed $v): bool => $v !== null),
        ]);
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
