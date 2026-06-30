<?php

namespace App\Modules\Forms\Components;

use App\Modules\Admin\Support\Cast;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Shared\Contracts\Component;

/**
 * Data-bound page component: embeds a lead-capture form (resolved by key within the current site,
 * RLS) into a Page-JSON page — e.g. the contact / quote form on /lien-he alongside the NAP block.
 * The view renders the form's fields, a hidden honeypot, and (only when configured) the Turnstile
 * widget; the success / error state is read from the session by the view. Fail-safe: an
 * unknown/inactive form_key yields a null action so the view renders nothing (the block-type
 * allow-list already gates rendering).
 */
final class FormComponent implements Component
{
    public function view(): string
    {
        return 'theme::sections.form';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        return [
            'form_key' => ['type' => 'text', 'default' => 'contact'],
        ];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function data(array $props): array
    {
        $key = is_string($props['form_key'] ?? null) && $props['form_key'] !== '' ? $props['form_key'] : 'contact';

        $form = Form::query()
            ->where('key', $key)
            ->where('status', 'active')
            ->with('fields')
            ->first();

        $locale = app()->getLocale();

        return [
            'props' => $props,
            'action' => $form !== null ? '/forms/'.$form->key : null,
            'heading' => $form !== null ? $this->localized($form->name, $locale) : '',
            'fields' => $form?->fields->map(fn (FormField $field): array => [
                'key' => $field->key,
                'type' => $field->type,
                'label' => $this->localized($field->label, $locale),
                'is_required' => $field->is_required,
            ])->all() ?? [],
            'turnstileSiteKey' => Cast::string(config('services.turnstile.site_key')),
        ];
    }

    /**
     * Pick the value for the active locale from a per-locale JSONB map (vi fallback).
     */
    private function localized(mixed $value, string $locale): string
    {
        $map = Cast::arr($value);

        return Cast::string($map[$locale] ?? $map['vi'] ?? null);
    }
}
