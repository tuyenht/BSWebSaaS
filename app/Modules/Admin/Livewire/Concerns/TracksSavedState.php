<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * The saved state of a settings section, for the shared save bar: the section names the fields
 * the bar compares (trackedState), remembers them once loaded and once saved (rememberSaved), and
 * passes unsavedCount() to the settings shell. Only a fingerprint of each field is kept, so a
 * secret typed into a field never travels back to the browser, and the browser cannot change it.
 * A list (the rows of a table) counts each row on its own.
 */
trait TracksSavedState
{
    /** @var array<string, string> field (list rows as "field.N") → fingerprint of its saved value */
    #[Locked]
    public array $savedState = [];

    /**
     * The fields the save bar compares with the saved values, as the section would store them.
     *
     * @return array<string, mixed>
     */
    abstract protected function trackedState(): array;

    protected function rememberSaved(): void
    {
        $this->savedState = self::fingerprints($this->trackedState());
    }

    /**
     * How many fields (or list rows) differ from the saved values; `$state` when the caller has
     * just built trackedState() for its own use.
     *
     * @param  array<string, mixed>|null  $state
     */
    protected function unsavedCount(?array $state = null): int
    {
        return count($this->differing($state ?? $this->trackedState()));
    }

    /**
     * The fields that differ from the saved values (a list that differs in any row, once).
     *
     * @return list<string>
     */
    protected function unsavedFields(): array
    {
        $fields = [];
        foreach ($this->differing($this->trackedState()) as $key) {
            $field = (string) preg_replace('/\.\d+\z/', '', $key);
            $fields[$field] = true;
        }

        return array_keys($fields);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function differing(array $state): array
    {
        $now = self::fingerprints($state);
        $keys = [];
        foreach (array_keys($now + $this->savedState) as $key) {
            if (($now[$key] ?? null) !== ($this->savedState[$key] ?? null)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    private static function fingerprints(array $state): array
    {
        $out = [];
        foreach ($state as $key => $value) {
            if (is_array($value) && $value !== [] && array_is_list($value)) {
                foreach ($value as $i => $row) {
                    $out[$key.'.'.$i] = hash('xxh128', (string) json_encode($row));
                }

                continue;
            }
            $out[$key] = hash('xxh128', (string) json_encode($value));
        }

        return $out;
    }
}
