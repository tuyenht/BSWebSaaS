<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Support\Str;

/**
 * A form as the «Biểu mẫu» editor holds it while it is being changed: the texts of one language (the
 * site's main one, or another one through translation()), whether it takes leads, and its fields in
 * order — the consent box left out (it is the platform's, always last, and never edited there). A
 * choice field's choices are one per line. SaveForm writes such a draft back; the other languages and every setting the editor does not show
 * (an empty field's example, length limits) stay as they were. A file field carries its kind
 * (`accept`, LeadFileKinds::KINDS) and its most files; any other field has '' and 0 there.
 *
 * @phpstan-type DraftField array{key: string, type: string, label: string, required: bool, choices: string, accept: string, max_files: int}
 * @phpstan-type Draft array{name: string, success: string, active: bool, emails: list<string>, fields: list<DraftField>}
 */
final class FormDraft
{
    /** The most fields one form holds (the consent box not counted). */
    public const int MAX_FIELDS = 30;

    /** The most choices one choice field holds. */
    public const int MAX_CHOICES = 50;

    /** Longest form name, field label and choice, in characters. */
    public const int MAX_NAME = 120;

    /** Longest thank-you sentence, in characters. */
    public const int MAX_SUCCESS = 500;

    /** The most addresses one form sends its lead emails to. */
    public const int MAX_EMAILS = 10;

    /** The most forms a site keeps (the list reads them all at once). */
    public const int MAX_FORMS = 200;

    /**
     * Whether a form asks visitors at least one thing besides a tick box: one that does not is
     * sent through the old contact rules, which ask for fields it never draws.
     *
     * @param  array<array-key, mixed>  $types
     */
    public static function hasAnswerField(array $types): bool
    {
        return array_filter($types, fn (mixed $t): bool => is_string($t) && $t !== 'checkbox') !== [];
    }

    /**
     * @return Draft
     */
    public static function fromForm(Form $form, string $locale): array
    {
        $fields = [];
        foreach ($form->fields as $field) {
            if ($field->key === Form::CONSENT_KEY) {
                continue;
            }
            $fields[] = [
                'key' => $field->key,
                'type' => $field->type,
                'label' => self::text($field->label, $locale),
                'required' => $field->is_required,
                'choices' => FormFieldTypes::isChoice($field->type) ? implode("\n", array_values(FormFieldTypes::choiceLabels($field, $locale))) : '',
                'accept' => $field->type === FormFieldTypes::FILE ? FormFieldTypes::fileKind($field) : '',
                'max_files' => $field->type === FormFieldTypes::FILE ? FormFieldTypes::maxFiles($field) : 0,
            ];
        }

        return [
            'name' => self::text($form->name, $locale),
            'success' => Cast::string(Cast::arr($form->success_message)[$locale] ?? null),
            'active' => $form->status === 'active',
            'emails' => Cast::strings($form->notify_emails),
            'fields' => $fields,
        ];
    }

    /**
     * $form as the editor holds it in $locale, a language other than the site's main one ($source):
     * the texts exactly as written in $locale (an empty one has no version there yet), everything
     * shared by every language (switch, emails, fields, their kinds, order and settings) as in $source.
     * A choice field's choices are one line per stored choice, in their order: a line left blank has
     * no version in $locale yet (trailing blank lines dropped).
     *
     * @return Draft
     */
    public static function translation(Form $form, string $locale, string $source): array
    {
        $draft = self::fromForm($form, $source);
        $byKey = $form->fields->keyBy('key');
        foreach ($draft['fields'] as $i => $f) {
            $field = $byKey->get($f['key']);
            if (! $field instanceof FormField) {
                continue;
            }
            $draft['fields'][$i]['label'] = self::own($field->label, $locale);
            $draft['fields'][$i]['choices'] = FormFieldTypes::isChoice($field->type)
                ? rtrim(implode("\n", array_map(fn (array $c): string => $c['label'][$locale] ?? '', FormFieldTypes::choices($field))))
                : '';
        }

        return [...$draft, 'name' => self::own($form->name, $locale), 'success' => self::own($form->success_message, $locale)];
    }

    /** A per-language text exactly as written in $locale ('' when it has none there), trimmed. */
    public static function own(mixed $map, string $locale): string
    {
        return trim(Cast::string(Cast::arr($map)[$locale] ?? null));
    }

    /**
     * The lines of a translated choices box, trimmed, in order, one per choice of the main language:
     * a blank line keeps its place (that choice has no version yet); trailing blank lines are dropped.
     *
     * @return list<string>
     */
    public static function translatedLines(string $text): array
    {
        $lines = array_map(trim(...), preg_split('/\R/u', $text) ?: []);
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * A new form of $purpose, in $locale.
     *
     * @return Draft
     */
    public static function template(string $purpose, string $locale): array
    {
        return [
            'name' => FormTemplates::name($purpose, $locale),
            'success' => '',
            'active' => true,
            'emails' => [],
            'fields' => FormTemplates::fields($purpose, $locale),
        ];
    }

    /**
     * The choices of a choice field from the lines typed in $locale: a line whose text is an existing
     * choice keeps that choice (its stored value and its other languages), a new line gets a value of
     * its own. A line that only shows another language's words for a choice with no name in $locale leaves
     * that choice without one. Empty lines are dropped, and so is a repeated line no further choice shows the words of.
     *
     * @param  list<array{value: string, label: array<string, string>}>  $existing
     * @return list<array{value: string, label: array<string, string>}>
     */
    public static function choicesFrom(string $lines, string $locale, array $existing): array
    {
        // Two choices can show the same words (each reads another language); give the lines of those
        // words the choices in order, so an untouched save keeps them all.
        $byText = [];
        foreach ($existing as $choice) {
            $byText[self::text($choice['label'], $locale) ?: $choice['value']][] = $choice;
        }

        $out = [];
        $taken = [];
        $seen = [];
        foreach (self::lines($lines, unique: false) as $line) {
            $choice = null;
            foreach ($byText[$line] ?? [] as $candidate) {
                if (! isset($taken[$candidate['value']])) {
                    $choice = $candidate;
                    break;
                }
            }
            if ($choice === null && isset($seen[$line])) {
                continue; // typed twice, with no other choice to take it
            }
            $seen[$line] = true;
            if ($choice === null) {
                $base = Str::slug($line) ?: 'lua-chon';
                $value = $base;
                for ($n = 2; isset($taken[$value]) || self::valueOfOther($value, $existing, $line, $locale); $n++) {
                    $value = $base.'-'.$n;
                }
                $choice = ['value' => $value, 'label' => [$locale => $line]];
            }
            // A line that matched a stored choice shows exactly that choice's words, so it keeps its names
            // as they are: one that only shows another language's words (or, with no name at all, its
            // value) stays without a name in $locale. Retyping those words on purpose cannot be told
            // apart from leaving the line alone; visitors read the same words either way.
            $taken[$choice['value']] = true;
            $out[] = $choice;
        }

        return $out;
    }

    /**
     * How many choices saving $lines in $locale would store: what choicesFrom returns, so a repeated
     * line that no other choice shows the words of does not count. $existing (the field's stored
     * choices) is only read when the lines alone pass the limit.
     *
     * @param  \Closure(): list<array{value: string, label: array<string, string>}>  $existing
     */
    public static function storedChoiceCount(string $lines, string $locale, \Closure $existing): int
    {
        $raw = count(self::lines($lines, unique: false));

        return $raw > self::MAX_CHOICES ? count(self::choicesFrom($lines, $locale, $existing())) : $raw;
    }

    /**
     * The non-empty lines of a choices box, trimmed, each once unless $unique is false.
     *
     * @return list<string>
     */
    public static function lines(string $text, bool $unique = true): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && (! $unique || ! in_array($line, $lines, true))) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Whether $email can take lead emails: one address of a usual shape, at most 254 characters. */
    public static function isEmail(string $email): bool
    {
        return mb_strlen($email) <= 254 && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $email) === 1
            && filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
    }

    /**
     * A per-language text in $locale, else the site's default language, else any stored language.
     * $default names the site's default language when the caller runs outside the site's context.
     */
    public static function text(mixed $map, string $locale, ?string $default = null): string
    {
        $map = Cast::arr($map);

        return Cast::string($map[$locale] ?? null)
            ?: (Cast::string($map[$default ?? app(SiteLocale::class)->default()] ?? null)
            ?: Cast::string(array_values(array_filter($map, fn (mixed $v): bool => is_string($v) && $v !== ''))[0] ?? null));
    }

    /**
     * Whether $value already names an existing choice other than the one typed as $line: a new
     * choice never takes the stored value of another one (its old leads would change meaning).
     *
     * @param  list<array{value: string, label: array<string, string>}>  $existing
     */
    private static function valueOfOther(string $value, array $existing, string $line, string $locale): bool
    {
        foreach ($existing as $choice) {
            if ($choice['value'] === $value && ((self::text($choice['label'], $locale) ?: $choice['value']) !== $line)) {
                return true;
            }
        }

        return false;
    }
}
