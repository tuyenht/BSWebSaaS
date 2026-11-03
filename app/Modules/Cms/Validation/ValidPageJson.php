<?php

namespace App\Modules\Cms\Validation;

use App\Modules\Shared\Support\VideoEmbed;
use App\Modules\Theme\Components\ComponentRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Server-side Page-JSON guard (ADR-08). The allow-list IS the ComponentRegistry — the
 * SAME registry BlockRenderer dispatches through — so registering a component extends
 * BOTH render and validation with no second list to maintain (the DRY invariant).
 *
 * Validates a bare list of blocks ([{type, props}, …]): each type must be registered
 * (an unknown "block lạ" is rejected), props must be an object, and every prop the block's
 * §E7 schema types as `url` — at any depth, through list `of` — must use a safe scheme
 * (no javascript:/data:/vbscript: — stored-XSS guard, since url props render into href/src).
 * URL-safety is SCHEMA-DRIVEN (by declared type, not prop name), so it cannot drift from the
 * sinks. A url prop the schema marks `video` must also be a YouTube/Vimeo address VideoEmbed
 * recognises. The body is also capped in count + size (storefront DoS / CWV guard).
 */
final readonly class ValidPageJson implements ValidationRule
{
    /** A page composes a bounded number of sections; reject pathological bodies. */
    private const int MAX_BLOCKS = 100;

    /** Serialized body byte ceiling (~256KB) — keeps entry_translations.body + render sane. */
    private const int MAX_BYTES = 262_144;

    public function __construct(private ComponentRegistry $registry) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('entries.err_blocks_invalid'));

            return;
        }

        if (count($value) > self::MAX_BLOCKS) {
            $fail(__('entries.err_blocks_too_many', ['max' => self::MAX_BLOCKS]));

            return;
        }

        if (strlen((string) json_encode($value)) > self::MAX_BYTES) {
            $fail(__('entries.err_body_too_large'));

            return;
        }

        foreach (array_values($value) as $index => $block) {
            $position = $index + 1;

            if (! is_array($block)) {
                $fail(__('entries.err_block_invalid', ['position' => $position]));

                return;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! $this->registry->has($type)) {
                $shown = is_string($type) ? $type : '?';
                $fail(__('entries.err_block_unsupported', ['type' => $shown]));

                return;
            }

            if (! array_key_exists('props', $block)) {
                continue;
            }

            if (! is_array($block['props'])) {
                $fail(__('entries.err_block_props_invalid', ['type' => $type]));

                return;
            }

            $schema = $this->registry->schemaFor($type);
            if (! $this->urlPropsSafe($block['props'], $schema)) {
                $fail(__('entries.err_block_link_invalid', ['type' => $type]));

                return;
            }

            if (! $this->choicesValid($block['props'], $schema)) {
                $fail(__('entries.err_block_option_invalid', ['type' => $type]));

                return;
            }

            $bound = $this->boundsViolation($block['props'], $schema, $type);
            if ($bound !== null) {
                $fail($bound);

                return;
            }

            if (! $this->videosRecognised($block['props'], $schema)) {
                $fail(__('entries.err_block_video_invalid'));

                return;
            }
        }
    }

    /**
     * Schema-driven URL safety: walk the block's declared §E7 schema and check every prop
     * the schema types as `url` (at any depth, through list `of`) with isSafeUrl — so safety
     * follows the declared TYPE, not the prop name. Props absent from the schema are never
     * rendered into a sink (the section-schema sink test enforces that), so they need no check.
     */
    private function urlPropsSafe(mixed $data, mixed $schema): bool
    {
        if (! is_array($schema)) {
            return true;
        }

        foreach ($schema as $prop => $spec) {
            $value = is_array($data) ? ($data[$prop] ?? null) : null;

            if (! $this->valueSafeForSpec($value, $spec)) {
                return false;
            }
        }

        return true;
    }

    /** Check one value against its schema spec — `url` leaves are scheme-checked; lists recurse. */
    private function valueSafeForSpec(mixed $value, mixed $spec): bool
    {
        if (! is_array($spec)) {
            return true;
        }

        if (($spec['type'] ?? null) === 'url') {
            // A url prop, if present, must be a safe string; a non-string would also crash the
            // e()'d href render (TypeError), so reject it here too.
            return $value === null || (is_string($value) && $this->isSafeUrl($value));
        }

        if (($spec['type'] ?? null) === 'image') {
            // An image prop is a media_id (positive int) or null (unset) — never a URL string.
            // Content references media by id (ADR-17: the path lives only in media.storage_key), so a
            // static URL in an image prop — even from a crafted wire payload past the picker — is
            // rejected outright. That makes the "choose from library, no static URL" rule real at the
            // save boundary, not only in the editor UI.
            return $value === null || (is_int($value) && $value > 0);
        }

        if (($spec['type'] ?? null) === 'list') {
            $of = $spec['of'] ?? null;
            if (! is_array($of) || ! is_array($value)) {
                return true;
            }

            // Scalar list: `of` IS the item spec. Object list: `of` is a field-map → recurse.
            $scalarOf = isset($of['type']) && is_string($of['type']);

            return array_all($value, fn ($item): bool => $scalarOf ? $this->valueSafeForSpec($item, $of) : $this->urlPropsSafe($item, $of));
        }

        // text / textarea / int → rendered through e(); a non-scalar (array/object) would crash
        // htmlspecialchars() at render (TypeError → 500s the published page), so the boundary that
        // distrusts the wire payload rejects it here too. null = an absent prop, which is fine.
        return $value === null || is_scalar($value);
    }

    /**
     * Every `enum` prop (at any depth, through list `of`) holds null or one of its declared options,
     * compared as strings exactly, and every text prop with a `pattern` holds null or a match. The render side falls back to the default for anything else
     * (SectionProps::choice), so this keeps a stored value honest rather than safe.
     */
    private function choicesValid(mixed $data, mixed $schema): bool
    {
        if (! is_array($schema)) {
            return true;
        }

        foreach ($schema as $prop => $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $value = is_array($data) ? ($data[$prop] ?? null) : null;
            $type = $spec['type'] ?? null;

            // A text prop with a `pattern` (a section's link anchor) must match it whole; blank means unset.
            if (is_string($spec['pattern'] ?? null) && $value !== null && $value !== ''
                && ! (is_string($value) && preg_match($spec['pattern'], $value) === 1)) {
                return false;
            }

            if ($type === 'enum') {
                $options = is_array($spec['options'] ?? null) ? $spec['options'] : [];
                if ($value !== null && ! in_array($value, $options, true)) {
                    return false;
                }

                continue;
            }

            $of = $spec['of'] ?? null;
            if ($type !== 'list' || ! is_array($of) || ! is_array($value)) {
                continue;
            }
            $scalarOf = isset($of['type']) && is_string($of['type']);
            foreach ($value as $item) {
                if (! ($scalarOf ? $this->choicesValid(['item' => $item], ['item' => $of]) : $this->choicesValid($item, $of))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The plain-language message for the first prop outside its declared bounds, or null: a list with
     * `max` (and `min`, checked once it holds an item) items, an int with `min`/`max` that is a whole
     * number inside them (blank = unset), a textarea with `max_lines` non-blank lines. Walks lists
     * of objects like the other schema walks.
     */
    private function boundsViolation(mixed $data, mixed $schema, string $type): ?string
    {
        if (! is_array($schema)) {
            return null;
        }

        foreach ($schema as $prop => $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $value = is_array($data) ? ($data[$prop] ?? null) : null;
            $kind = $spec['type'] ?? null;

            if ($kind === 'list' && is_array($value)) {
                $count = count($value);
                if (is_int($spec['max'] ?? null) && $count > $spec['max']) {
                    return __('entries.err_block_list_max', ['type' => $type, 'max' => $spec['max']]);
                }
                if (is_int($spec['min'] ?? null) && $count > 0 && $count < $spec['min']) {
                    return __('entries.err_block_list_min', ['type' => $type, 'min' => $spec['min']]);
                }
                $of = $spec['of'] ?? null;
                if (is_array($of) && ! (isset($of['type']) && is_string($of['type']))) {
                    foreach ($value as $item) {
                        $found = $this->boundsViolation($item, $of, $type);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                }

                continue;
            }

            if ($kind === 'int' && (isset($spec['min']) || isset($spec['max'])) && $value !== null && $value !== '') {
                $number = is_int($value) ? $value : (is_string($value) && preg_match('/\A-?\d{1,15}\z/', $value) === 1 ? (int) $value : null);
                if ($number === null || $number < ($spec['min'] ?? PHP_INT_MIN) || $number > ($spec['max'] ?? PHP_INT_MAX)) {
                    return __('entries.err_block_number_invalid', ['type' => $type]);
                }
            }

            if ($kind === 'textarea' && is_int($spec['max_lines'] ?? null) && is_string($value)
                && count(array_filter(preg_split('/\R/u', $value) ?: [], static fn (string $line): bool => trim($line) !== '')) > $spec['max_lines']) {
                return __('entries.err_block_lines_max', ['type' => $type, 'max' => $spec['max_lines']]);
            }
        }

        return null;
    }

    /**
     * Every url prop the schema marks `video` (at any depth, through list `of`) holds null, a blank
     * string (unset) or an address VideoEmbed recognises — the storefront can only play those, so
     * anything else is refused at save with a plain message rather than shown as an empty block.
     */
    private function videosRecognised(mixed $data, mixed $schema): bool
    {
        if (! is_array($schema)) {
            return true;
        }

        foreach ($schema as $prop => $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $value = is_array($data) ? ($data[$prop] ?? null) : null;

            if (($spec['video'] ?? false) === true) {
                if (is_string($value) && trim($value) !== '' && VideoEmbed::player($value) === null) {
                    return false;
                }

                continue;
            }

            $of = $spec['of'] ?? null;
            if (($spec['type'] ?? null) !== 'list' || ! is_array($of) || ! is_array($value)) {
                continue;
            }
            $scalarOf = isset($of['type']) && is_string($of['type']);
            foreach ($value as $item) {
                if (! ($scalarOf ? $this->videosRecognised(['item' => $item], ['item' => $of]) : $this->videosRecognised($item, $of))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Reject dangerous URL schemes; whitespace/control chars are stripped to defeat obfuscation. */
    private function isSafeUrl(string $value): bool
    {
        $normalized = strtolower((string) preg_replace('/[\s\x00-\x1F]+/', '', $value));

        return array_all(['javascript:', 'data:', 'vbscript:'], fn ($scheme): bool => ! str_starts_with($normalized, (string) $scheme));
    }
}
