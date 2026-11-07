<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use DomainException;

/**
 * A quick change to a product's name or address refused for a reason the member can fix. The
 * message IS the plain-language sentence to show, already translated; nothing was saved.
 */
final class ProductEditRefused extends DomainException {}
