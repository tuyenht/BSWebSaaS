<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use RuntimeException;
use Throwable;

/**
 * A settings write refused for a reason the screen can say in plain words: `reason` is a short
 * code (image_invalid, slot_invalid, section_invalid, busy), `field` the slot or section it is
 * about. busy: another save of the site's settings held the lock longer than the screen waits
 * (SettingsWrite) — nothing was written, and trying again later works.
 */
final class SettingsChangeRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $field,
        ?Throwable $previous = null,
    ) {
        parent::__construct("{$reason}: {$field}", 0, $previous);
    }
}
