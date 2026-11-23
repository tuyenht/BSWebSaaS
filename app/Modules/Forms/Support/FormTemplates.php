<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

/**
 * The starting points of a new form («Bắt đầu từ mẫu nào?»): each purpose loads a few common fields
 * once, when the form is created; the purpose itself is not stored. The fields are written in the
 * site's main language (the wording comes from lang/*.json), and every form also gets the consent box
 * (SaveForm), which is why the count shown with each purpose adds one.
 *
 * The quote and application purposes also bring an attachment field («Tệp đính kèm»): a drawing
 * (up to 3 files) and a CV (one). One whose kind the platform holds back (forms.file_kinds) is
 * left out.
 *
 * @phpstan-import-type DraftField from FormDraft
 */
final class FormTemplates
{
    /** @var list<string> in the picker's order; `custom` is the blank one */
    public const array PURPOSES = ['contact', 'quote', 'consult', 'application', 'custom'];

    /** @var array<string, string> purpose => its icon in the picker */
    public const array ICONS = [
        'contact' => 'ri-chat-3',
        'quote' => 'ri-price-tag-3',
        'consult' => 'ri-customer-service-2',
        'application' => 'ri-user-star',
        'custom' => 'ri-draft',
    ];

    /**
     * The fields a purpose starts with, in order, labelled in $locale.
     *
     * @return list<DraftField>
     */
    public static function fields(string $purpose, string $locale): array
    {
        $f = static fn (string $key, string $type, bool $required, string $label, string $choices = '', string $accept = '', int $max = 0): array => [
            'key' => $key,
            'type' => $type,
            'label' => (string) __('form_builder.tpl.'.$label, [], $locale),
            'required' => $required,
            'choices' => $choices === '' ? '' : (string) __('form_builder.tpl.'.$choices, [], $locale),
            'accept' => $accept,
            'max_files' => $max,
        ];
        $kinds = FormFieldTypes::fileKinds();
        $file = static fn (string $key, string $label, string $kind, int $max): array => in_array($kind, $kinds, true)
            ? [$f($key, FormFieldTypes::FILE, false, $label, '', $kind, $max)]
            : [];
        $name = $f('name', 'text', true, 'name');
        $phone = $f('phone', 'tel', false, 'phone');
        $phoneRequired = $f('phone', 'tel', true, 'phone');
        $email = $f('email', 'email', false, 'email');

        return match ($purpose) {
            'quote' => [$name, $phone, $email, $f('company', 'text', false, 'company'), $f('message', 'textarea', true, 'message_quote'), ...$file('drawing', 'drawing', 'cad', 3)],
            'consult' => [$name, $phoneRequired, $f('need', 'textarea', false, 'need'), $f('callback', 'select', false, 'callback', 'callback_choices')],
            'application' => [$name, $phoneRequired, $email, $f('position', 'text', false, 'position'), $f('message', 'textarea', false, 'intro'), ...$file('cv', 'cv', 'cv', 1)],
            'custom' => [$name, $phone, $email],
            default => [$name, $email, $phone, $f('message', 'textarea', true, 'message')],
        };
    }

    /** The name a new form of this purpose starts with ('' for the blank one). */
    public static function name(string $purpose, string $locale): string
    {
        return $purpose === 'custom' || ! in_array($purpose, self::PURPOSES, true) ? '' : (string) __('form_builder.purpose.'.$purpose, [], $locale);
    }
}
