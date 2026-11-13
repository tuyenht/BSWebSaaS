<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Shared\Support\RequestId;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Audit trail (design.md §29). Best-effort: records every sensitive action
 * (role/permission/entitlement/domain/billing) and never lets its own failure break the
 * business flow. Actor + site + request-id are resolved from context, and during a
 * support session the staff member behind the member's account (impersonator_*) and the
 * session's code (support_session_ulid).
 *
 * The insert runs in its own savepoint: every tenant request is one PostgreSQL transaction,
 * and a failed statement aborts it — swallowing the error alone would leave the caller's next
 * query failing, or its COMMIT silently rolling back everything the request wrote.
 *
 * The one failure it does not swallow is a concurrency error (deadlock, serialization failure, a
 * transaction already aborted — Shared\Support\ConcurrencyError). Laravel rethrows one raised in
 * the savepoint without rolling back to it, so the caller's transaction is aborted and cannot be
 * rescued here: hiding it would let the caller report success while its COMMIT rolls everything
 * back. It is rethrown so the owner of the transaction rolls back and retries or defers.
 */
final class AuditService
{
    /** The console row that issues a support sign-in link: the only row whose caller names the session code. */
    public const string SUPPORT_SESSION_ISSUED = 'support.session.issued';

    /**
     * The end of a support session. Inside the session it carries the session's own code; written
     * from outside any support session (the console's "Kết thúc phiên ngay", a newer session of the
     * same staff member) the caller names the session it ends.
     */
    public const string SUPPORT_SESSION_ENDED = 'support.session.ended';

    /**
     * @param  array<string, mixed>  $context  optional overrides: site_id, actor_id,
     *                                         actor_label, auditable_type, auditable_id, meta,
     *                                         support_session_ulid (read only on SUPPORT_SESSION_ISSUED,
     *                                         and on SUPPORT_SESSION_ENDED outside a support session)
     */
    public function record(string $action, array $context = []): void
    {
        try {
            $entry = $this->entry($action, $context);
            $entry->getConnection()->transaction(fn (): bool => $entry->save());
        } catch (Throwable $e) {
            // Inside a transaction a lock conflict has already aborted it (Laravel rethrows without
            // rolling back to the savepoint), so the caller must hear about it. At level 0 Laravel
            // rolled the insert back in full and nothing is left aborted: stay best-effort there.
            if (ConcurrencyError::is($e) && (new AuditLog)->getConnection()->transactionLevel() > 0) {
                throw $e;
            }

            report($e); // best-effort — swallow so the caller's flow is never blocked
        }
    }

    /**
     * Records the row or throws: for a change that must not happen unaudited, run inside the same
     * transaction as the change, so a failed insert rolls the change back too. Same context as
     * record().
     *
     * @param  array<string, mixed>  $context
     * @return string the row's ulid
     */
    public function recordOrFail(string $action, array $context = []): string
    {
        $entry = $this->entry($action, $context);
        $entry->saveOrFail();

        return $entry->ulid;
    }

    /** @param  array<string, mixed>  $context */
    private function entry(string $action, array $context): AuditLog
    {
        $user = Auth::user();
        $current = app(CurrentSite::class);
        $request = request();
        // Signed in as a member for support: the member is the actor, the staff member stands behind.
        $support = app(SupportSession::class)->current();

        return new AuditLog([
            // Kept only when it looks like one (uuid, ray id…): it comes from the client.
            'request_id' => RequestId::accept($request->headers->get(RequestId::HEADER)),
            'actor_id' => $context['actor_id'] ?? $user?->getAuthIdentifier(),
            'actor_label' => $context['actor_label'] ?? ($user instanceof User ? $user->email : null),
            'impersonator_id' => $support['staff_id'] ?? null,
            'impersonator_label' => $support['staff_label'] ?? null,
            // The caller names the code only on the console's "issued" row and on an "ended" row
            // written outside any support session; everywhere else it comes from the session itself,
            // so no caller can stamp a row of a session with another session's code.
            'support_session_ulid' => match (true) {
                $action === self::SUPPORT_SESSION_ISSUED => $context['support_session_ulid'] ?? $support['session_ulid'] ?? null,
                $action === self::SUPPORT_SESSION_ENDED && $support === null => $context['support_session_ulid'] ?? null,
                default => $support['session_ulid'] ?? null,
            },
            // A caller that names the site (even none) is taken at its word; only an unnamed one
            // falls back to the site of the request.
            'site_id' => array_key_exists('site_id', $context) ? $context['site_id'] : ($current->hasId() ? $current->id() : null),
            'action' => $action,
            'auditable_type' => $context['auditable_type'] ?? null,
            'auditable_id' => $context['auditable_id'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => $context['meta'] ?? [],
            'created_at' => now(),
        ]);
    }
}
