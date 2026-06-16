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
 */
final class AuditService
{
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

            AuditLog::create([
                'request_id' => $request->headers->get('X-Request-Id'),
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
        } catch (Throwable $e) {
            report($e); // best-effort — swallow so the caller's flow is never blocked
        }
    }
}
