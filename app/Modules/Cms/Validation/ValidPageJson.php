<?php

namespace App\Modules\Cms\Validation;

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\VideoEmbed;
use App\Modules\Theme\Components\ButtonLink;
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
 * recognises. The body is also capped in count + size (storefront DoS / CWV guard). A url prop the
 * schema marks `action` is a button link: its typed value (`<prop>_do`, ButtonLink) must be well
 * formed, an older plain address in it may not point at another host (`//`, `/\`), and the pages and
 * library files the body's buttons name must be this site's (one query each, only when named).
 */
final readonly class ValidPageJson implements ValidationRule
{
    /** A page composes a bounded number of sections; reject pathological bodies. */
    private const int MAX_BLOCKS = 100;

    /**
     * Serialized body byte ceiling (~256KB) — keeps entry_translations.body + render sane. Measured
     * in UTF-8 bytes, letters and slashes as they are (close to what jsonb stores): escaped, each
     * accented Vietnamese letter would count six bytes and a long article would be refused well under
     * MAX_HTML_CHARS.
     */
    private const int MAX_BYTES = 262_144;

    /**
     * Characters one formatted-text (`html`) prop may hold. Product descriptions have no cap of their
     * own to reuse, so this is named here: room for a long article with its markup (about 15,000
     * words); the whole-body ceiling (MAX_BYTES, in UTF-8 bytes) still applies, so a text mostly of
     * accented Vietnamese letters (two to three bytes each) meets that ceiling first.
     */
    public const int MAX_HTML_CHARS = 100_000;

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

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // Not valid UTF-8: it could never be stored (the body cast refuses it), so it is refused here.
            $fail(__('entries.err_blocks_invalid'));

            return;
        }

        if (strlen($json) > self::MAX_BYTES) {
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
            $html = $this->htmlViolation($block['props'], $schema);
            if ($html !== null) {
                $fail($html);

                return;
            }

            if (! $this->videoPropsValid($block['props'], $schema)) {
                $fail(__('entries.err_block_bg_video_invalid'));

                return;
            }

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

        $missing = $this->missingButtonTarget($value);
        if ($missing !== null) {
            $fail($missing);
        }
    }

    /**
     * The message for a button that names a page or a library file this site does not have (another
     * site's, or one deleted for good), or null. The Trash counts as kept: a restored page or file
     * works again. Documents only for a file. Site-scoped queries (BelongsToSite + RLS).
     *
     * @param  array<array-key, mixed>  $blocks
     */
    private function missingButtonTarget(array $blocks): ?string
    {
        $pages = [];
        $files = [];
        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! is_string($type) || ! is_array($block['props'] ?? null)) {
                continue;
            }
            $refs = ButtonLink::refs($block['props'], $this->registry->schemaFor($type));
            $pages = array_merge($pages, $refs['pages']);
            $files = array_merge($files, $refs['files']);
        }
        $pages = array_values(array_unique($pages));
        $files = array_values(array_unique($files));

        if ($pages !== [] && Entry::withTrashed()->whereIn('ulid', $pages)->count() < count($pages)) {
            return __('entries.err_block_button_page_missing');
        }
        if ($files !== [] && Media::withTrashed()->whereIn('storage_key', $files)->whereIn('mime', ButtonLink::DOCUMENT_MIMES)->count() < count($files)) {
            return __('entries.err_block_button_file_missing');
        }

        return null;
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

            if (ButtonLink::labelProp($spec) !== null && ! $this->buttonSafe($value, is_array($data) ? ($data[$prop.ButtonLink::SUFFIX] ?? null) : null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A button link: its typed value, when set, well formed (ButtonLink::problem); an older plain
     * address, when filled, one the storefront draws (ButtonLink::legacy: never another host, a
     * script scheme, a control character or a mailto: carrying extra header fields).
     */
    private function buttonSafe(mixed $legacy, mixed $do): bool
    {
        if ($do !== null && ButtonLink::problem($do) !== null) {
            return false;
        }

        return ! is_string($legacy) || trim($legacy) === '' || ButtonLink::legacy($legacy) !== null;
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

        if (($spec['type'] ?? null) === 'html') {
            // Printed WITHOUT escaping, so it is named explicitly rather than left to the scalar
            // default: null or a string, nothing else. Structure only — the HTML itself is cleaned
            // by the body casts on every write (ADR-19), never here.
            return $value === null || is_string($value);
        }

        if (($spec['type'] ?? null) === 'image') {
            // An image prop is a media_id (positive int) or null (unset) — never a URL string.
            // Content references media by id (ADR-17: the path lives only in media.storage_key), so a
            // static URL in an image prop — even from a crafted wire payload past the picker — is
            // rejected outright. That makes the "choose from library, no static URL" rule real at the
            // save boundary, not only in the editor UI.
            return $value === null || (is_int($value) && $value > 0);
        }

        if (($spec['type'] ?? null) === 'video') {
            // A media_id or null, like an image. At the top of a schema videoPropsValid() has already
            // checked it with its own message, so this only decides a video prop deeper down (in a
            // list), which that check does not walk.
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
     * Every `video` prop (a background video from the library, declared at the top of a block's
     * schema) holds null (none) or a media_id (positive int) — never an address, like an image prop.
     * Whether the id names a video of this site is not decided here: the renderer resolves only this
     * site's video files and renders the block without a video otherwise, and the usage index skips
     * an id of another site — the same rule as an image id.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     */
    private function videoPropsValid(array $props, array $schema): bool
    {
        foreach ($schema as $prop => $spec) {
            if (is_array($spec) && ($spec['type'] ?? null) === 'video') {
                $value = $props[$prop] ?? null;
                if ($value !== null && ! (is_int($value) && $value > 0)) {
                    return false;
                }
            }
        }

        return true;
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
                // A list that names `min_of` counts only the items whose field of that name is not
                // blank — the ones the storefront can actually draw.
                $usable = is_string($spec['min_of'] ?? null) ? $this->usableItems($value, $spec['min_of']) : $count;
                if (is_int($spec['min'] ?? null) && $count > 0 && $usable < $spec['min']) {
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
     * The plain-language message for the first `html` prop (at any depth, through list `of`) that is
     * neither null nor a string, or longer than MAX_HTML_CHARS; null when all are fine.
     */
    private function htmlViolation(mixed $data, mixed $schema): ?string
    {
        if (! is_array($schema)) {
            return null;
        }

        foreach ($schema as $prop => $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $value = is_array($data) ? ($data[$prop] ?? null) : null;

            if (($spec['type'] ?? null) === 'html') {
                if ($value !== null && ! is_string($value)) {
                    return __('entries.err_block_html_invalid');
                }
                if (is_string($value) && mb_strlen($value) > self::MAX_HTML_CHARS) {
                    return __('entries.err_block_html_too_long', ['max' => self::MAX_HTML_CHARS]);
                }

                continue;
            }

            $of = $spec['of'] ?? null;
            if (($spec['type'] ?? null) !== 'list' || ! is_array($of) || ! is_array($value)) {
                continue;
            }
            $scalarOf = isset($of['type']) && is_string($of['type']);
            foreach ($value as $item) {
                $found = $scalarOf ? $this->htmlViolation(['item' => $item], ['item' => $of]) : $this->htmlViolation($item, $of);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * How many items of a list have a non-blank text in `$field`.
     *
     * @param  array<array-key, mixed>  $items
     */
    private function usableItems(array $items, string $field): int
    {
        $usable = 0;
        foreach ($items as $item) {
            $text = is_array($item) ? ($item[$field] ?? null) : null;
            if (is_string($text) && trim($text) !== '') {
                $usable++;
            }
        }

        return $usable;
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
