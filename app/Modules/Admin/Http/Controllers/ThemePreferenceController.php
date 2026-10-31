<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Persists the per-user Customizer override (users.preferences.theme) — the fetch()/JSON
 * endpoint behind the floating launcher. Registered once per plane (admin.* + platform.*)
 * because the two consoles live on different hosts and fetch() must stay same-origin.
 *
 * Only the submitted axes are written (per-field pin); `preferences` is guarded on the
 * model, so this validated setter is the single write path. Responses mirror the DAM
 * controllers: validation errors come back as JSON 422 for the fetch client.
 */
final class ThemePreferenceController
{
    public function store(Request $request, AdminChromeResolver $resolver): JsonResponse
    {
        $user = $this->user();

        try {
            $validated = $request->validate($this->rules());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $preferences = $user->preferences;
        $preferences['theme'] = array_merge(Cast::arr($preferences['theme'] ?? null), Cast::arr($validated));
        $user->preferences = $preferences;
        $user->save();

        return response()->json(['attributes' => $resolver->attributes($user)]);
    }

    /**
     * Reset drops this PLANE's per-user pins, letting the site/app default show through.
     * The tenant admin clears every axis; the platform console — whose launcher shows
     * only the Scheme axis — clears just the scheme, so an operator's sidebar/topbar/
     * position pins for tenant admins survive a console reset (the sidebar size too: the
     * console's menu button shares that one preference with the tenant admin).
     */
    public function destroy(AdminChromeResolver $resolver): JsonResponse
    {
        $user = $this->user();

        $preferences = $user->preferences;
        if (AdminPlane::isPlatform()) {
            $theme = Cast::arr($preferences['theme'] ?? null);
            unset($theme['scheme']);
            if ($theme === []) {
                unset($preferences['theme']);
            } else {
                $preferences['theme'] = $theme;
            }
        } else {
            unset($preferences['theme']);
        }
        $user->preferences = $preferences;
        $user->save();

        return response()->json(['attributes' => $resolver->attributes($user)]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        $rules = [];
        foreach (AdminChromeResolver::FIELDS as $field => $allowed) {
            $rules[$field] = ['sometimes', 'string', Rule::in($allowed)];
        }

        return $rules;
    }
}
