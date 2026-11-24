<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Forms\Models\FormField;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The one place that knows what a form field type accepts from a visitor. The lead request builds
 * its rules here and the storefront form block shows only the fields this says can be collected,
 * so a field the visitor sees is always one the server validates and keeps — and nothing else is.
 *
 * options (jsonb) of a choice field: {"choices": [{"value": "cnc", "label": {"vi": "…", "en": "…"}}]};
 * of a text kind or a select, optionally the example shown in the empty field (a select's empty pick):
 * {"placeholder": {"vi": "…", "en": "…"}}
 * — `value` is the stable key stored with the lead, `label` what the visitor reads per locale.
 * validation (jsonb), all optional: {"max_length": n} (text kinds), {"min": n, "max": n} (number),
 * {"max_choices": n} (checkboxes), {"not_past": true} (date).
 * A file field («Tệp đính kèm»): options {"accept": "cad"|"cv"|"img"} (LeadFileKinds::KINDS, the
 * board's three fixed kinds; the visitor never types extensions), validation {"max_files": 1..5}.
 * Its answer is never kept in the lead's data: each file sent becomes a row of
 * form_submission_files (SubmitForm → StoreLeadFile), and the rules here only ask that what was
 * sent under its name is files.
 *
 * form_fields.type's CHECK allows STORED_TYPES today. number, date, radio and checkboxes already
 * validate here so widening that CHECK is the only schema step they need; until then no form can
 * hold one.
 */
final class FormFieldTypes
{
    /** @var list<string> */
    public const array STORED_TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox', self::FILE];

    /** @var list<string> */
    public const array TYPES = [...self::STORED_TYPES, 'number', 'date', 'radio', 'checkboxes'];

    /**
     * Types the storefront form block can draw today. Any other type is neither shown nor collected,
     * so a visitor is never asked for an answer they cannot give.
     *
     * @var list<string>
     */
    public const array DRAWN_TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox', self::FILE];

    /** The attachment type: its files are rows of their own, never part of the lead's data. */
    public const string FILE = 'file';

    /** A file field's kind when it names none, and the most files it takes unless set (board «Biểu mẫu»). */
    public const string DEFAULT_KIND = 'cad';

    public const int DEFAULT_MAX_FILES = 3;

    /** «Số tệp tối đa» offers 1 to this many. */
    public const int MAX_FILES = 5;

    /** @var list<string> Types the visitor picks from the field's choices. */
    public const array CHOICE_TYPES = ['select', 'radio', 'checkboxes'];

    /**
     * Input and error names the platform itself uses on every lead (the page's language, the
     * honeypot, the posted form's name, the form-wide error): a site field may never take one.
     * Turnstile's, `_token` and `_method` already fail KEY_PATTERN.
     *
     * @var list<string>
     */
    public const array RESERVED_KEYS = ['locale', 'company_website', 'lead_form', 'form'];

    /** A field key is a plain lowercase name: no dots (nested input), dashes or leading underscore. */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /** Hard caps at the storage boundary, whatever a site configures. */
    private const array MAX_LENGTH = ['text' => 255, 'email' => 255, 'tel' => 32, 'textarea' => 5000];

    /**
     * The most files read from one field in one send, whatever it allows: a list longer than that is
     * not a visitor's (PHP's max_file_uploads stops at 20 per request anyway).
     */
    private const int UPLOADS_CAP = 20;

    /** A number answer: at most 15 digits before the point and 6 after — never an exponent. */
    private const string NUMBER_PATTERN = '/^-?\d{1,15}(\.\d{1,6})?$/';

    public static function isUsableKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1 && ! in_array($key, self::RESERVED_KEYS, true);
    }

    public static function isChoice(string $type): bool
    {
        return in_array($type, self::CHOICE_TYPES, true);
    }

    /**
     * Whether a visitor can answer this field: a type the block draws, under a usable key, and a
     * choice field with at least one choice (with none it could never be answered — a required
     * one would make the whole form impossible to send). A field that fails is neither shown nor kept.
     */
    public static function isCollectable(FormField $field): bool
    {
        return in_array($field->type, self::DRAWN_TYPES, true)
            && self::isUsableKey($field->key)
            && (! self::isChoice($field->type) || self::choiceValues($field) !== [])
            && ($field->type !== self::FILE || in_array(self::fileKind($field), self::fileKinds(), true));
    }

    /**
     * The kinds a file field may take on this platform, in the board's order: LeadFileKinds::KINDS
     * less any that config('forms.file_kinds') leaves out.
     *
     * @return list<string>
     */
    public static function fileKinds(): array
    {
        $on = Cast::strings(config('forms.file_kinds'));

        return array_values(array_filter(array_keys(LeadFileKinds::KINDS), fn (string $kind): bool => in_array($kind, $on, true)));
    }

    /** The kind a file field takes (LeadFileKinds::KINDS); one it does not name reads as the default. */
    public static function fileKind(FormField $field): string
    {
        $kind = Cast::string(Cast::arr($field->options)['accept'] ?? null);

        return array_key_exists($kind, LeadFileKinds::KINDS) ? $kind : self::DEFAULT_KIND;
    }

    /** The most files a file field takes: its own setting between 1 and MAX_FILES, else the default. */
    public static function maxFiles(FormField $field): int
    {
        $max = Cast::int(Cast::arr($field->validation)['max_files'] ?? 0);

        return $max >= 1 && $max <= self::MAX_FILES ? $max : self::DEFAULT_MAX_FILES;
    }

    /** The file input's `accept`: the kind's extensions, each with its dot (`.step,.stp,…`). */
    public static function accept(string $kind): string
    {
        return implode(',', array_map(fn (string $extension): string => '.'.$extension, LeadFileKinds::KINDS[$kind] ?? []));
    }

    /**
     * The validation rules of one field, built from its type, required flag, choices and limits.
     *
     * @return list<mixed>
     */
    public static function rules(FormField $field): array
    {
        $type = $field->type;
        $validation = Cast::arr($field->validation);
        $required = $field->is_required;

        if ($type === 'checkbox') {
            return $required ? ['accepted'] : ['nullable', 'boolean'];
        }
        if ($type === self::FILE) {
            // Only that what came under its name is files. Each file is then kept or refused on its
            // own (StoreLeadFile): a file of the wrong kind, too large or over the count never
            // costs the visitor their lead.
            return [$required ? 'required' : 'nullable', 'array', 'max:'.self::UPLOADS_CAP, self::onlyUploads(...)];
        }

        $rules = [$required ? 'required' : 'nullable'];

        switch ($type) {
            case 'email':
                array_push($rules, 'string', 'email', 'max:'.self::maxLength($type, $validation));
                break;
            case 'number':
                array_push($rules, 'regex:'.self::NUMBER_PATTERN, 'numeric');
                foreach (['min', 'max'] as $bound) {
                    if (is_int($validation[$bound] ?? null) || is_float($validation[$bound] ?? null)) {
                        $rules[] = $bound.':'.$validation[$bound];
                    }
                }
                break;
            case 'date':
                $rules[] = 'date_format:Y-m-d';
                // "Not before today" is the site's today: a lead sent at 01:00 in Viet Nam is still
                // the previous day in UTC.
                if (($validation['not_past'] ?? false) === true) {
                    $rules[] = 'after_or_equal:'.app(SiteTime::class)->now()->toDateString();
                }
                break;
            case 'select':
            case 'radio':
                array_push($rules, 'string', Rule::in(self::choiceValues($field)));
                break;
            case 'checkboxes':
                array_push($rules, 'list', 'max:'.self::maxChoices($field, $validation));
                break;
            default: // text, tel, textarea
                array_push($rules, 'string', 'max:'.self::maxLength($type, $validation));
        }

        return $rules;
    }

    /**
     * Rules keyed by a nested path: each pick of a checkboxes field is one of its choices, once.
     *
     * @return array<string, list<mixed>>
     */
    public static function nestedRules(FormField $field): array
    {
        if ($field->type !== 'checkboxes') {
            return [];
        }

        return [$field->key.'.*' => ['string', 'distinct', Rule::in(self::choiceValues($field))]];
    }

    /**
     * The stable values of a choice field.
     *
     * @return list<string>
     */
    public static function choiceValues(FormField $field): array
    {
        return array_column(self::choices($field), 'value');
    }

    /**
     * The choices of a field, each {value, label{locale: text}}; entries without a value, and a
     * value already listed, are dropped.
     *
     * @return list<array{value: string, label: array<string, string>}>
     */
    public static function choices(FormField $field): array
    {
        $choices = [];
        foreach (Cast::arr(Cast::arr($field->options)['choices'] ?? []) as $choice) {
            $choice = Cast::arr($choice);
            $value = Cast::string($choice['value'] ?? null);
            if ($value === '' || isset($choices[$value])) {
                continue;
            }
            $labels = [];
            foreach (Cast::arr($choice['label'] ?? []) as $locale => $text) {
                $labels[(string) $locale] = Cast::string($text);
            }
            $choices[$value] = ['value' => $value, 'label' => $labels];
        }

        return array_values($choices);
    }

    /**
     * What a visitor reads for each choice, keyed by its value: the label in their language, else in
     * Vietnamese, else the value itself, so a choice is never shown blank.
     *
     * @return array<string, string>
     */
    public static function choiceLabels(FormField $field, string $locale): array
    {
        $labels = [];
        foreach (self::choices($field) as $choice) {
            $labels[$choice['value']] = ($choice['label'][$locale] ?? '') ?: (($choice['label']['vi'] ?? '') ?: $choice['value']);
        }

        return $labels;
    }

    /** A file field's answer holds uploaded files and nothing else (never a typed value). */
    private static function onlyUploads(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (is_array($value) ? $value : [$value] as $one) {
            if (! $one instanceof UploadedFile) {
                $fail(Cast::string(__('forms.err_field_invalid')));

                return;
            }
        }
    }

    /** @param array<mixed> $validation */
    private static function maxLength(string $type, array $validation): int
    {
        $cap = self::MAX_LENGTH[$type] ?? 255;
        $wanted = Cast::int($validation['max_length'] ?? 0);

        return $wanted > 0 ? min($wanted, $cap) : $cap;
    }

    /**
     * How many boxes a checkboxes answer may tick: never more than it has choices, fewer when the
     * site asks for that.
     *
     * @param  array<mixed>  $validation
     */
    private static function maxChoices(FormField $field, array $validation): int
    {
        $count = count(self::choiceValues($field));
        $wanted = Cast::int($validation['max_choices'] ?? 0);

        return $wanted > 0 ? min($wanted, $count) : $count;
    }
}
