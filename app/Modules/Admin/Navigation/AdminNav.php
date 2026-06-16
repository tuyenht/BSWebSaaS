<?php

namespace App\Modules\Admin\Navigation;

use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The dynamic admin menu (design.md §15.2/§366): the content_sections enabled for the
 * current site ∩ the user's permissions — entitlement (layer #1) then RBAC (layer #2).
 * content_sections is BelongsToSite, so the query is auto-scoped to the current site
 * and fail-closed (no site / no user → empty menu).
 */
final class AdminNav
{
    /**
     * @return list<NavItem>
     */
    public function items(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $sections = ContentSection::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($sections as $section) {
            if ($this->visibleTo($section, $user)) {
                $items[] = new NavItem(
                    key: $section->key,
                    label: $section->label,
                    url: route('admin.entries.index', ['section' => $section->key]),
                    icon: $section->icon,
                );
            }
        }

        return $items;
    }

    private function visibleTo(ContentSection $section, Authenticatable $user): bool
    {
        $prefix = $section->permission_prefix;

        if (! is_string($prefix) || $prefix === '') {
            return true;
        }

        return Gate::forUser($user)->allows("{$prefix}.view");
    }
}
