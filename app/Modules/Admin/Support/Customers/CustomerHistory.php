<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Customers;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\TenantHistory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One customer's history (design 03, "Lịch sử"), newest first: what changed, when and by whom.
 * `tenant_history`, `plans` and `users` are platform-wide (D6); the platform console reads them with
 * no tenant context.
 *
 * Read by keyset on (created_at, id), newest first, which the index (tenant_id, created_at DESC,
 * id DESC) serves in order: the first PAGE rows, then "older" adds the next PAGE below the oldest
 * row shown. What the page holds is the oldest row shown, as an encrypted cursor bound to its
 * customer: the browser never sees a row id, and a cursor it made up, altered or took from another
 * customer reads as the first page. However far "older" went, one render reads at most MAX_ROWS
 * rows, those just above and at the anchor. The plans' and the person's names come in the same
 * statement (joins), never a query per row.
 */
final class CustomerHistory
{
    public const int PAGE = 10;

    /** The most rows one render shows: past it, the newest rows give way to the older ones. */
    public const int MAX_ROWS = self::PAGE * 50;

    /**
     * The rows from the newest down to $until (the first PAGE when $until is empty), whether older
     * rows remain, whether newer rows were left out above MAX_ROWS (the page then offers the way back
     * to the newest), and the cursor of the oldest row shown (what "older" takes).
     *
     * @return array{rows: list<array{kind: string, from: string|null, to: string|null, reason: string|null, actor: string|null, at: string}>, more: bool, cut: bool, oldest: string|null}
     */
    public function read(int $tenantId, string $until): array
    {
        $anchor = self::anchor($until, $tenantId);
        $cut = false;
        if ($anchor === null) {
            $rows = $this->base($tenantId)->limit(self::PAGE + 1)->get();
            $more = $rows->count() > self::PAGE;
            $rows = $rows->take(self::PAGE);
        } else {
            // read from the anchor up (the index scanned backwards), so the rows shown always end
            // at the anchor and "older" keeps moving even past MAX_ROWS
            $rows = $this->base($tenantId)->whereRaw('(h.created_at, h.id) >= (?::timestamptz, ?)', $anchor)
                ->reorder('h.created_at')->orderBy('h.id')->limit(self::MAX_ROWS + 1)->get();
            $cut = $rows->count() > self::MAX_ROWS;
            $rows = $rows->take(self::MAX_ROWS)->reverse()->values();
            $more = $this->base($tenantId)->whereRaw('(h.created_at, h.id) < (?::timestamptz, ?)', $anchor)->exists();
        }

        $last = $rows->last();

        return [
            'rows' => array_values($rows->map(fn (object $r): array => self::row($r))->all()),
            'more' => $more,
            'cut' => $cut,
            'oldest' => $last === null ? null : self::cursor($last, $tenantId),
        ];
    }

    /**
     * The cursor of the PAGE-th row older than $cursor (or of the oldest one, when fewer remain):
     * what the page holds after "older". An unreadable $cursor gives ''.
     */
    public function older(int $tenantId, string $cursor): string
    {
        $anchor = self::anchor($cursor, $tenantId);
        if ($anchor === null) {
            return '';
        }
        $next = $this->base($tenantId)->select(['h.id', 'h.created_at'])
            ->whereRaw('(h.created_at, h.id) < (?::timestamptz, ?)', $anchor)
            ->limit(self::PAGE)->get()->all();
        $last = $next === [] ? null : $next[array_key_last($next)];

        return $last === null ? $cursor : self::cursor($last, $tenantId);
    }

    private function base(int $tenantId): Builder
    {
        return DB::table('tenant_history as h')
            ->leftJoin('plans as f', 'f.id', '=', 'h.from_plan_id')
            ->leftJoin('plans as t', 't.id', '=', 'h.to_plan_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')
            ->where('h.tenant_id', $tenantId)
            ->select(['h.id', 'h.created_at', 'h.kind', 'h.reason', 'h.from_plan_id', 'h.to_plan_id', 'h.actor_user_id',
                'f.name as from_name', 't.name as to_name', 'u.name as actor_name'])
            ->orderByDesc('h.created_at')->orderByDesc('h.id');
    }

    /**
     * @return array{kind: string, from: string|null, to: string|null, reason: string|null, actor: string|null, at: string}
     */
    private static function row(object $r): array
    {
        $kind = Cast::string($r->kind ?? null);

        return [
            'kind' => $kind === TenantHistory::KIND_PLAN_CHANGED ? $kind : 'other',
            // null: no plan on that side
            'from' => ($r->from_plan_id ?? null) === null ? null : Cast::string($r->from_name ?? null),
            'to' => ($r->to_plan_id ?? null) === null ? null : Cast::string($r->to_name ?? null),
            'reason' => is_string($r->reason ?? null) && trim($r->reason) !== '' ? trim($r->reason) : null,
            // null: made from the console (no person)
            'actor' => ($r->actor_user_id ?? null) === null ? null : Cast::string($r->actor_name ?? null),
            'at' => Cast::string($r->created_at ?? null),
        ];
    }

    /** The customer, then the row: a cursor read for another customer is refused. */
    private static function cursor(object $r, int $tenantId): string
    {
        return Crypt::encryptString($tenantId.'|'.Cast::string($r->created_at ?? null).'|'.Cast::int($r->id ?? null));
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function anchor(string $cursor, int $tenantId): ?array
    {
        if ($cursor === '') {
            return null;
        }
        try {
            $plain = Crypt::decryptString($cursor);
        } catch (DecryptException) {
            return null;
        }
        if (preg_match('/^([1-9]\d{0,18})\|(.+)\|([1-9]\d{0,18})$/', $plain, $m) !== 1 || $m[1] !== (string) $tenantId) {
            return null;
        }

        return [$m[2], (int) $m[3]];
    }
}
