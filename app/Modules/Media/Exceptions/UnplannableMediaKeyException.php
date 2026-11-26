<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/** A stored key media:rekey-site cannot give a new key to; the message says why (for a person). */
final class UnplannableMediaKeyException extends RuntimeException {}
