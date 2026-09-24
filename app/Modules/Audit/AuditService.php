<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Audit trail (design.md §29). Best-effort: records every sensitive action
 * (role/permission/entitlement/domain/billing) but NEVER throws — auditing must not
 * break the business flow. Actor + site + request-id are resolved from context.
 *
 * The insert runs in its own savepoint: every tenant request is one PostgreSQL transaction,
 * and a failed statement aborts it — swallowing the error alone would leave the caller's next
 * query failing, or its COMMIT silently rolling back everything the request wrote.
 */
final class AuditService
{
    /** A request id is kept only when it looks like one (uuid, ray id…); it comes from the client. */
    private const string REQUEST_ID_PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    /**
     * @param  array<string, mixed>  $context  optional overrides: site_id, actor_id,
     *                                         actor_label, auditable_type, auditable_id, meta
     */
    public function record(string $action, array $context = []): void
    {
        try {
            $user = Auth::user();
            $current = app(CurrentSite::class);
            $request = request();

            $entry = new AuditLog([
                'request_id' => $this->requestId($request->headers->get('X-Request-Id')),
                'actor_id' => $context['actor_id'] ?? $user?->getAuthIdentifier(),
                'actor_label' => $context['actor_label'] ?? ($user instanceof User ? $user->email : null),
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
            report($e); // best-effort — swallow so the caller's flow is never blocked
        }
    }

    private function requestId(?string $header): ?string
    {
        return $header !== null && preg_match(self::REQUEST_ID_PATTERN, $header) === 1 ? $header : null;
    }
}
