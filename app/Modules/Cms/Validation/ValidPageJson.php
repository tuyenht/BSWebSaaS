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
 * (an unknown "block lạ" is rejected) and props, when present, must be an object.
 * Per-prop schemas (full JSON-Schema) are a deliberate later step — this enforces the
 * minimal shape the renderer relies on (it Blade-escapes props and skips unknown types).
 */
final class ValidPageJson implements ValidationRule
{
    public function __construct(private readonly ComponentRegistry $registry) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Danh sách khối không hợp lệ.');

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

            if (array_key_exists('props', $block) && ! is_array($block['props'])) {
                $fail("Thuộc tính của khối \"{$type}\" không hợp lệ.");

                return;
            }
        }
    }
}
