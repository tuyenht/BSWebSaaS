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
     * @param  array<string, mixed>  $context  optional overrides: site_id, actor_id,
     *                                         actor_label, auditable_type, auditable_id, meta,
     *                                         support_session_ulid (read only on SUPPORT_SESSION_ISSUED)
     */
    public function record(string $action, array $context = []): void
    {
        try {
            $user = Auth::user();
            $current = app(CurrentSite::class);
            $request = request();
            // Signed in as a member for support: the member is the actor, the staff member stands behind.
            $support = app(SupportSession::class)->current();

            $entry = new AuditLog([
                // Kept only when it looks like one (uuid, ray id…): it comes from the client.
                'request_id' => RequestId::accept($request->headers->get(RequestId::HEADER)),
                'actor_id' => $context['actor_id'] ?? $user?->getAuthIdentifier(),
                'actor_label' => $context['actor_label'] ?? ($user instanceof User ? $user->email : null),
                'impersonator_id' => $support['staff_id'] ?? null,
                'impersonator_label' => $support['staff_label'] ?? null,
                // The caller names the code only on the console's "issued" row; everywhere else it comes
                // from the session itself, so no caller can stamp a row with another session's code.
                'support_session_ulid' => ($action === self::SUPPORT_SESSION_ISSUED ? $context['support_session_ulid'] ?? null : null)
                    ?? $support['session_ulid'] ?? null,
                'site_id' => $context['site_id'] ?? ($current->hasId() ? $current->id() : null),
                'action' => $action,
                'auditable_type' => $context['auditable_type'] ?? null,
                'auditable_id' => $context['auditable_id'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'meta' => $context['meta'] ?? [],
                'created_at' => now(),
            ]);

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
}
