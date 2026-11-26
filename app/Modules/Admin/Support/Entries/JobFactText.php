<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The words of one box of the job card (EditsJobCard), judged when empty too: an implicit rule, since
 * a box with no words in any language is refused as one with too many is (the validator skips the
 * other rules of an empty string).
 */
final class JobFactText implements ValidationRule
{
    /** Run on an empty value too (Illuminate\Validation\InvokableValidationRule::make). */
    public bool $implicit = true;

    /** @param  Closure(string, Closure(string): mixed): void  $check */
    public function __construct(private readonly Closure $check) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        ($this->check)($attribute, $fail);
    }
}
