<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Forms\Models\Form;

/**
 * How far each language of a form is written, for the «Biểu mẫu» editor's language bar: the texts a
 * visitor reads that the site's main language has (the form's name, the thank-you sentence when it
 * has one, each field's label, and each choice field's choices as one), and how many of them another
 * language has too. A choice field counts once every one of its choices has that language. The
 * consent box is the platform's sentence, never counted. Read from the form and its fields as loaded.
 */
final class FormLangStates
{
    /**
     * @param  list<string>  $locales  the site's languages
     * @return array{total: int, done: array<string, int>}
     */
    public static function read(Form $form, string $source, array $locales): array
    {
        /** @var list<callable(string): bool> $texts one per text the main language has */
        $texts = [];
        $has = static fn (mixed $map, string $code): bool => FormDraft::own($map, $code) !== '';
        foreach ([$form->name, $form->success_message] as $map) {
            if ($has($map, $source)) {
                $texts[] = static fn (string $code): bool => $has($map, $code);
            }
        }
        foreach ($form->fields as $field) {
            if ($field->key === Form::CONSENT_KEY) {
                continue;
            }
            if ($has($field->label, $source)) {
                $label = $field->label;
                $texts[] = static fn (string $code): bool => $has($label, $code);
            }
            $choices = FormFieldTypes::isChoice($field->type) ? FormFieldTypes::choices($field) : [];
            if ($choices !== []) {
                $texts[] = static fn (string $code): bool => array_filter($choices, static fn (array $c): bool => trim($c['label'][$code] ?? '') === '') === [];
            }
        }

        $done = [];
        foreach ($locales as $code) {
            $done[$code] = count(array_filter($texts, static fn (callable $filled): bool => $filled($code)));
        }

        return ['total' => count($texts), 'done' => $done];
    }
}
