<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A customer's contact phone that ContactPhone can store; pair it with `nullable` (it is optional). */
final class ContactPhoneRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ContactPhone::normalize($value) === null) {
            $fail(__('customers.phone_invalid'));
        }
    }
}
