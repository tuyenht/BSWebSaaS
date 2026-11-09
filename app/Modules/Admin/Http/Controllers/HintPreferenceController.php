<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\FirstHints;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Shared\Support\Cast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Remembers that the signed-in member pressed "Đã hiểu" on a first-time explanation
 * (users.preferences.hints.<key> = true) — the fetch()/JSON endpoint behind <x-bs-admin::hint-ok>.
 * Registered once per plane, like the theme preference, because the consoles live on different
 * hosts and fetch() stays same-origin.
 *
 * The key must be one of FirstHints::KEYS; only that one key of the column is written
 * (UserPreferences), so the member's other preferences stay as they are. `preferences` is guarded
 * on the model: this validated setter is the hints' single write path.
 */
final class HintPreferenceController
{
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $validated = $request->validate(['key' => ['required', 'string', Rule::in(FirstHints::KEYS)]]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $key = Cast::string(Cast::arr($validated)['key'] ?? null);
        UserPreferences::set($user, ['hints', $key], true);

        return response()->json(['dismissed' => $key]);
    }
}
