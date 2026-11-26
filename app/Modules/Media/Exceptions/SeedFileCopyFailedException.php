<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/** The storage copy of a shared seed file to a website's own key failed; nothing was written for it. */
final class SeedFileCopyFailedException extends RuntimeException {}
