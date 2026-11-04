<?php

namespace App\Modules\Theme\Components;

/**
 * Finds and rewrites the media props of a section's §E7 schema — `image` and `video`, the props whose
 * value is a media_id (ADR-17: content references media by id, never by a stored URL). ids() and
 * retain() see both kinds (the page's one media lookup, the usage index, dropping media that left the
 * library); replace() turns image ids into URLs and replaceVideos() video ids into playable sources,
 * so a video id never becomes an <img> address and an image id never a video. Handles depth exactly as
 * ValidPageJson walks `url` props: a scalar image leaf, and a list `of` an object carrying image
 * fields (e.g. partners.logos[].image_url). One traversal serves both callers — collect the ids
 * (batch-resolve / validate) and swap each id for its resolved URL (render) — so the walk rule lives
 * in one place and cannot drift between validation and rendering.
 */
final class SchemaImages
{
    /** The prop types whose value is a media_id. */
    private const array MEDIA_TYPES = ['image', 'video'];

    /**
     * Every positive-int media_id referenced by an image or video prop in $props, per $schema.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @return list<int>
     */
    public static function ids(array $props, array $schema): array
    {
        $ids = [];
        self::walk($props, $schema, function (mixed $value) use (&$ids): mixed {
            if (is_int($value) && $value > 0) {
                $ids[] = $value;
            }

            return $value;
        }, self::MEDIA_TYPES);

        return array_values(array_unique($ids));
    }

    /**
     * $props with every image leaf resolved for rendering:
     *   - a media_id (positive int) → $map[id], or null when the id is absent (unresolved /
     *     other-tenant / trashed) — the section blade renders null as "no image";
     *   - a legacy URL string → passed through unchanged, so content seeded/migrated before the
     *     move to media_id still renders during the expand phase (removed once all data is media_id);
     *   - anything else → null.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @param  array<int, string>  $map  media_id => resolved URL
     * @return array<array-key, mixed>
     */
    public static function replace(array $props, array $schema, array $map): array
    {
        return self::walk($props, $schema, static fn (mixed $value): mixed => match (true) {
            is_int($value) && $value > 0 => $map[$value] ?? null,
            is_string($value) => $value,
            default => null,
        }, ['image']);
    }

    /**
     * $props with every video leaf resolved for rendering: a media_id → its source from $videos
     * (only this site's video files are in it), anything else — an unknown, other-site, trashed or
     * non-video id, a string — → null, which the section renders as "no video".
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @param  array<int, array{url: string, type: string}>  $videos  media_id => source
     * @return array<array-key, mixed>
     */
    public static function replaceVideos(array $props, array $schema, array $videos): array
    {
        return self::walk($props, $schema, static fn (mixed $value): mixed => is_int($value) && $value > 0 ? ($videos[$value] ?? null) : null, ['video']);
    }

    /**
     * $props with every image or video leaf whose media_id is NOT in $keep cleared to null (the value
     * the editor's "remove" writes); every other leaf is untouched. Used to drop references to
     * media that has left the library before old content is written back.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @param  list<int>  $keep  media_ids that still exist
     * @return array<array-key, mixed>
     */
    public static function retain(array $props, array $schema, array $keep): array
    {
        $kept = array_flip($keep);

        return self::walk($props, $schema, static fn (mixed $value): mixed => is_int($value) && $value > 0 && ! isset($kept[$value]) ? null : $value, self::MEDIA_TYPES);
    }

    /**
     * Walk $props against $schema, applying $fn to each leaf whose type is one of $types; returns
     * $props with those leaves replaced by $fn's return. Every other prop is left untouched.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @param  callable(mixed): mixed  $fn
     * @param  list<string>  $types
     * @return array<array-key, mixed>
     */
    private static function walk(array $props, array $schema, callable $fn, array $types): array
    {
        foreach ($schema as $prop => $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $type = $spec['type'] ?? null;

            if (in_array($type, $types, true)) {
                if (array_key_exists($prop, $props)) {
                    $props[$prop] = $fn($props[$prop]);
                }

                continue;
            }

            if ($type !== 'list') {
                continue;
            }

            $of = $spec['of'] ?? null;
            $items = $props[$prop] ?? null;
            if (! is_array($of) || ! is_array($items)) {
                continue;
            }

            // Same disambiguation as ValidPageJson: a scalar list has `of => ['type' => '<string>']`,
            // an object list has `of => ['field' => ['type' => …], …]` (no string `type` at the top).
            $scalarOf = isset($of['type']) && is_string($of['type']);

            foreach ($items as $i => $item) {
                if ($scalarOf) {
                    if (in_array($of['type'], $types, true)) {
                        $items[$i] = $fn($item);
                    }
                } elseif (is_array($item)) {
                    $items[$i] = self::walk($item, $of, $fn, $types);
                }
            }

            $props[$prop] = $items;
        }

        return $props;
    }
}
