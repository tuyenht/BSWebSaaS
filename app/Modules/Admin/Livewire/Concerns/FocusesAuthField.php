<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * The sign-in steps put the cursor where the next keystroke belongs: the first field in error, a
 * wrong code or password, a code field emptied for a fresh code. The auth layout listens for
 * `auth-focus` and focuses (and selects) the input whose id is given; every field's id is its
 * property name.
 */
trait FocusesAuthField
{
    protected function focusField(string $id): void
    {
        $this->dispatch('auth-focus', field: $id);
    }

    /**
     * validate(), and when it fails the cursor goes to the first field in error.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     */
    protected function validateOrFocus(array $rules, array $attributes = []): void
    {
        try {
            $this->validate($rules, [], $attributes);
        } catch (ValidationException $e) {
            $first = array_key_first($e->errors());
            if (is_string($first)) {
                $this->focusField($first);
            }

            throw $e;
        }
    }
}
