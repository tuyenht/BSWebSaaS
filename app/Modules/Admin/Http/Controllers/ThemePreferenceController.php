<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Identity\Models\User;
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

    /** Reset = drop the whole per-user override; the site/app default shows through. */
    public function destroy(AdminChromeResolver $resolver): JsonResponse
    {
        $user = $this->user();

        $preferences = $user->preferences;
        unset($preferences['theme']);
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
