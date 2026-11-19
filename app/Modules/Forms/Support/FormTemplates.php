<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

/**
 * The starting points of a new form («Bắt đầu từ mẫu nào?»): each purpose loads a few common fields
 * once, when the form is created; the purpose itself is not stored. The fields are written in the
 * site's main language (the wording comes from lang/*.json), and every form also gets the consent box
 * (SaveForm), which is why the count shown with each purpose adds one.
 *
 * The board's quote and application purposes also bring an attachment field; attachments are not
 * built yet (form_fields.type cannot hold one), so those fields are left out until they are.
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
     * @return list<array{key: string, type: string, label: string, required: bool, choices: string}>
     */
    public static function fields(string $purpose, string $locale): array
    {
        $f = static fn (string $key, string $type, bool $required, string $label, string $choices = ''): array => [
            'key' => $key,
            'type' => $type,
            'label' => (string) __('form_builder.tpl.'.$label, [], $locale),
            'required' => $required,
            'choices' => $choices === '' ? '' : (string) __('form_builder.tpl.'.$choices, [], $locale),
        ];
        $name = $f('name', 'text', true, 'name');
        $phone = $f('phone', 'tel', false, 'phone');
        $phoneRequired = $f('phone', 'tel', true, 'phone');
        $email = $f('email', 'email', false, 'email');

        return match ($purpose) {
            'quote' => [$name, $phone, $email, $f('company', 'text', false, 'company'), $f('message', 'textarea', true, 'message_quote')],
            'consult' => [$name, $phoneRequired, $f('need', 'textarea', false, 'need'), $f('callback', 'select', false, 'callback', 'callback_choices')],
            'application' => [$name, $phoneRequired, $email, $f('position', 'text', false, 'position'), $f('message', 'textarea', false, 'intro')],
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
