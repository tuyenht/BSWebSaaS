<?php

namespace App\Modules\Cms\Validation;

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
 * sinks. The body is also capped in count + size (storefront DoS / CWV guard).
 */
final class ValidPageJson implements ValidationRule
{
    /** A page composes a bounded number of sections; reject pathological bodies. */
    private const MAX_BLOCKS = 100;

    /** Serialized body byte ceiling (~256KB) — keeps entry_translations.body + render sane. */
    private const MAX_BYTES = 262_144;

    public function __construct(private readonly ComponentRegistry $registry) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Danh sách khối không hợp lệ.');

            return;
        }

        if (count($value) > self::MAX_BLOCKS) {
            $fail('Trang có quá nhiều khối (tối đa '.self::MAX_BLOCKS.').');

            return;
        }

        if (strlen((string) json_encode($value)) > self::MAX_BYTES) {
            $fail('Nội dung trang quá lớn.');

            return;
        }

        foreach (array_values($value) as $index => $block) {
            $position = $index + 1;

            if (! is_array($block)) {
                $fail("Khối #{$position} không hợp lệ.");

                return;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! $this->registry->has($type)) {
                $shown = is_string($type) ? $type : '?';
                $fail("Khối \"{$shown}\" không được hỗ trợ.");

                return;
            }

            if (! array_key_exists('props', $block)) {
                continue;
            }

            if (! is_array($block['props'])) {
                $fail("Thuộc tính của khối \"{$type}\" không hợp lệ.");

                return;
            }

            if (! $this->urlPropsSafe($block['props'], $this->registry->get($type)->schema())) {
                $fail("Liên kết trong khối \"{$type}\" không hợp lệ.");

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

        if (($spec['type'] ?? null) === 'list') {
            $of = $spec['of'] ?? null;
            if (! is_array($of) || ! is_array($value)) {
                return true;
            }

            // Scalar list: `of` IS the item spec. Object list: `of` is a field-map → recurse.
            $scalarOf = isset($of['type']) && is_string($of['type']);
            foreach ($value as $item) {
                if (! ($scalarOf ? $this->valueSafeForSpec($item, $of) : $this->urlPropsSafe($item, $of))) {
                    return false;
                }
            }

            return true;
        }

        // text / textarea / int → rendered through e(); a non-scalar (array/object) would crash
        // htmlspecialchars() at render (TypeError → 500s the published page), so the boundary that
        // distrusts the wire payload rejects it here too. null = an absent prop, which is fine.
        return $value === null || is_scalar($value);
    }

    /** Reject dangerous URL schemes; whitespace/control chars are stripped to defeat obfuscation. */
    private function isSafeUrl(string $value): bool
    {
        $normalized = strtolower((string) preg_replace('/[\s\x00-\x1F]+/', '', $value));

        foreach (['javascript:', 'data:', 'vbscript:'] as $scheme) {
            if (str_starts_with($normalized, $scheme)) {
                return false;
            }
        }

        return true;
    }
}
