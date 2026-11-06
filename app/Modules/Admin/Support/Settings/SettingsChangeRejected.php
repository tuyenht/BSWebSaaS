<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use RuntimeException;

/**
 * A settings write refused for a reason the screen can say in plain words: `reason` is a short
 * code (image_invalid, slot_invalid, section_invalid), `field` the slot or section it is about.
 */
final class SettingsChangeRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $field,
    ) {
        parent::__construct("{$reason}: {$field}");
    }
}
