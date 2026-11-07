<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Shared\Support\Cast;

/**
 * A lead's answers as people read them: under the label of the field they answer, in the form's
 * order, a picked choice by its label. A ticked box shows the sentence stored with the lead (what
 * the visitor agreed to when they sent it), never the box's current wording. An answer whose field
 * was removed later still shows, under its key — nothing the visitor sent is hidden.
 */
final class LeadAnswers
{
    /** Keys of the original contact form, labelled even when the form has no rows for them. */
    private const array CONTACT_KEYS = ['name', 'email', 'phone', 'message'];

    /**
     * @param  array<mixed>  $data  the submission's data
     * @return list<array{key: string, label: string, value: string, ticked: bool, wide: bool}>
     */
    public static function for(?Form $form, array $data, string $locale): array
    {
        $answers = [];
        $seen = [];

        foreach ($form->fields ?? [] as $field) {
            $seen[$field->key] = true;
            $answer = self::answer($field, $data[$field->key] ?? null, $locale);
            if ($answer !== null) {
                $answers[] = $answer;
            }
        }

        foreach ($data as $key => $value) {
            $key = (string) $key;
            $text = self::plain($value);
            if (isset($seen[$key]) || $text === '') {
                continue;
            }
            $label = in_array($key, self::CONTACT_KEYS, true) ? Cast::string(__('forms.lead_field_'.$key)) : $key;
            $answers[] = ['key' => $key, 'label' => $label, 'value' => $text, 'ticked' => false, 'wide' => $key === 'message'];
        }

        return $answers;
    }

    /** @return array{key: string, label: string, value: string, ticked: bool, wide: bool}|null */
    private static function answer(FormField $field, mixed $value, string $locale): ?array
    {
        if ($field->type === 'checkbox') {
            if ($value === null || $value === false || $value === '' || $value === '0') {
                return null;
            }
            $sentence = is_string($value) && $value !== '1' ? $value : Cast::string(__('forms.detail_ticked_no_text'));

            return ['key' => $field->key, 'label' => Cast::string(__('forms.detail_ticked')), 'value' => $sentence, 'ticked' => true, 'wide' => true];
        }

        if (FormFieldTypes::isChoice($field->type)) {
            $labels = [];
            foreach (FormFieldTypes::choices($field) as $choice) {
                $labels[$choice['value']] = ($choice['label'][$locale] ?? '') ?: (($choice['label']['vi'] ?? '') ?: $choice['value']);
            }
            $picked = is_array($value) ? $value : [$value];
            $value = array_map(static fn (mixed $one): string => $labels[self::plain($one)] ?? self::plain($one), $picked);
        }

        $text = self::plain($value);
        if ($text === '') {
            return null;
        }

        return [
            'key' => $field->key,
            'label' => self::label($field, $locale),
            'value' => $text,
            'ticked' => false,
            'wide' => $field->type === 'textarea',
        ];
    }

    private static function label(FormField $field, string $locale): string
    {
        $labels = Cast::arr($field->label);
        $label = Cast::string($labels[$locale] ?? $labels['vi'] ?? null);

        return $label !== '' ? $label : $field->key;
    }

    /** A stored answer as one line of text: a list joined with commas, anything else as typed. */
    private static function plain(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(self::plain(...), $value), static fn (string $one): bool => $one !== ''));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
