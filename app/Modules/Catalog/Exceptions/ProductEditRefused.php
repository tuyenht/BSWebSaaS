<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use DomainException;

/**
 * A quick change to a product's name or address refused for a reason the member can fix. The
 * message IS the plain-language sentence to show, already translated; nothing was saved.
 * `$field` names the form field the sentence belongs to when it is not the obvious one.
 */
final class ProductEditRefused extends DomainException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
