<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Workflow Tầng-1 (design.md §15.4 — permission-driven, no configuration). An actor
 * carrying entries.publish publishes straight through: entry + translation go
 * `published`, published_at is stamped, and the route is synced (with a 301 on a slug
 * change). Anyone else who can submit parks the entry as `pending` for a publisher to
 * review. Only published entries with a published translation reach the public
 * (RouteResolver). Tầng-2 (state-machine) is a deliberate later step.
 */
final class PublishEntry
{
    public function __construct(private readonly RouteSync $routes) {}

    /** @return 'published'|'pending' the resulting lifecycle status */
    public function handle(Entry $entry, string $locale, User $actor): string
    {
        $translation = $entry->translationFor($locale)
            ?? throw new RuntimeException("Entry {$entry->id} has no '{$locale}' translation to publish.");

        $gate = Gate::forUser($actor);

        if ($gate->allows('publish', $entry)) {
            $entry->update([
                'status' => 'published',
                'published_at' => $entry->published_at ?? now(),
            ]);
            $translation->update(['status' => 'published']);

            $this->routes->sync($entry, $locale, $translation->slug);

            return 'published';
        }

        if ($gate->allows('submit', $entry)) {
            $entry->update(['status' => 'pending']);
            $translation->update(['status' => 'pending']);

            return 'pending';
        }

        throw new AuthorizationException('Bạn không có quyền đăng hoặc gửi duyệt nội dung này.');
    }
}
