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
 * (an unknown "block lạ" is rejected), props must be an object, URL props must use a
 * safe scheme (no javascript:/data:/vbscript: — stored-XSS guard, since props are
 * author-controlled and rendered into href/src), and the body is capped in count +
 * size (storefront DoS / CWV guard). Per-prop schemas (full JSON-Schema) remain a
 * deliberate later step.
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

            foreach ($block['props'] as $key => $propValue) {
                if (is_string($key) && $this->isUrlProp($key) && is_string($propValue) && ! $this->isSafeUrl($propValue)) {
                    $fail("Liên kết trong khối \"{$type}\" không hợp lệ.");

                    return;
                }
            }
        }
    }

    /** A prop whose value lands in an href/src sink (url, href, src, *_url). */
    private function isUrlProp(string $key): bool
    {
        return $key === 'url' || $key === 'href' || $key === 'src' || str_ends_with($key, '_url');
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
