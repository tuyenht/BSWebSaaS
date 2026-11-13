<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Plans;

use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Support\Number;

/**
 * How the plan screens (design 30) put a plan feature into words: its name, what it gives, the
 * unit of a limit, the short line of the plan list and the sentence of "Khách dùng gói này sẽ
 * thấy…". The words come from the language files, looked up by the feature; a feature added to
 * FeatureRegistry without words yet shows its registry name and its bare value.
 */
final class PlanFeatureText
{
    /** Feature key → the stem of its words in the language files. */
    private const array STEMS = [
        'workflow.editorial' => 'workflow',
        'sites.max' => 'sites',
        'media.storage_mb' => 'media',
        'locales.max' => 'locales',
        'custom_domains.max' => 'domains',
        'rbac.custom_roles' => 'roles',
        'rbac.category_scopes' => 'scopes',
    ];

    /** The smallest value a limit takes on this screen (a plan always lets a customer have one website in one language). */
    private const array MIN = ['sites.max' => 1, 'locales.max' => 1];

    public static function name(string $key): string
    {
        return self::word($key, 'name') ?? (FeatureRegistry::all()[$key]['name'] ?? $key);
    }

    public static function benefit(string $key): string
    {
        return self::word($key, 'ben') ?? '';
    }

    public static function unit(string $key): string
    {
        return self::word($key, 'unit') ?? '';
    }

    public static function min(string $key): int
    {
        return self::MIN[$key] ?? 0;
    }

    public static function isLimit(string $key): bool
    {
        return (FeatureRegistry::all()[$key]['kind'] ?? 'boolean') === 'limit';
    }

    /** Whether the feature belongs to the customer across their websites (else to each website). */
    public static function wholeCustomer(string $key): bool
    {
        return (FeatureRegistry::all()[$key]['scope'] ?? 'site') === 'tenant';
    }

    /** "Có" / "Không", or a number with its unit. */
    public static function value(string $key, bool|int $value): string
    {
        if (! self::isLimit($key)) {
            return __($value === true ? 'admin.plans_yes' : 'admin.plans_no');
        }

        return trim(self::number((int) $value).' '.self::unit($key));
    }

    /** The plan list's short line for one feature, or null for a switch that is off. */
    public static function short(string $key, bool|int $value): ?string
    {
        if (! self::isLimit($key)) {
            return $value === true ? (self::word($key, 'short') ?? self::name($key)) : null;
        }

        return self::word($key, 'short', ['n' => self::number((int) $value)]) ?? self::value($key, $value);
    }

    /** What a customer on the plan sees, for "Khách dùng gói này sẽ thấy…". */
    public static function sees(string $key, bool|int $value): string
    {
        if (! self::isLimit($key)) {
            return self::word($key, $value === true ? 'on' : 'off') ?? self::name($key).': '.self::value($key, $value);
        }

        if ((int) $value === 0) {
            $none = self::word($key, 'see_none');
            if ($none !== null) {
                return $none;
            }
        }

        return self::word($key, 'see', ['n' => self::number((int) $value)]) ?? self::name($key).': '.self::value($key, $value);
    }

    public static function number(int $n): string
    {
        return (string) Number::format($n, locale: app()->getLocale());
    }

    /** @param  array<string, string>  $replace */
    private static function word(string $key, string $part, array $replace = []): ?string
    {
        $stem = self::STEMS[$key] ?? null;
        if ($stem === null) {
            return null;
        }

        $line = 'admin.plans_f_'.$stem.'_'.$part;
        $text = __($line, $replace);

        return is_string($text) && $text !== $line ? $text : null;
    }
}
