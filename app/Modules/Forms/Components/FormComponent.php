<?php

namespace App\Modules\Forms\Components;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Shared\Contracts\Component;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PreviewMode;
use App\Modules\Theme\Chrome\BranchMap;
use App\Modules\Theme\Components\SectionProps;

/**
 * Data-bound page component: embeds a lead-capture form (resolved by key within the current site,
 * RLS) into a Page-JSON page — e.g. the contact / quote form on /lien-he alongside the NAP block.
 * The view renders the form's fields, a hidden honeypot, and (only when configured) the Turnstile
 * widget; the success / error state is read from the session by the view. In the admin's preview of a
 * page (PreviewMode) the form is drawn switched off: no address to send to, no Turnstile, no outcome. Fail-safe: an
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
            // The form's title on this page; empty means the form's own name.
            'heading' => ['type' => 'text'],
            // Optional, opt-in: a line under the heading, and a map that puts the form in a card beside it.
            'description' => ['type' => 'textarea'],
            'map_embed' => ['type' => 'url'],
            // Where the map beside the form comes from: the address above (embed, the default) or the site's
            // branches and factories, drawn with a chooser (branches).
            'map_source' => ['type' => 'enum', 'options' => SectionProps::MAP_SOURCES, 'default' => 'embed'],
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
        $preview = PreviewMode::on();

        return [
            'props' => $props,
            'action' => $form !== null ? '/forms/'.$form->key : null,
            'locale' => $locale,
            'heading' => $form !== null ? SectionProps::text($props, 'heading', $this->localized($form->name, $locale)) : '',
            // Only fields a visitor can answer: the lead request validates and keeps exactly these.
            'fields' => $form?->fields->filter(FormFieldTypes::isCollectable(...))->values()->map(fn (FormField $field): array => [
                'key' => $field->key,
                'type' => $field->type,
                'label' => $this->localized($field->label, $locale),
                'is_required' => $field->is_required,
                'choices' => FormFieldTypes::isChoice($field->type) ? FormFieldTypes::choiceLabels($field, $locale) : [],
                'placeholder' => $this->localized(Cast::arr($field->options)['placeholder'] ?? null, $locale),
            ])->all() ?? [],
            'branchMap' => SectionProps::choice($props, 'map_source', SectionProps::MAP_SOURCES) === 'branches'
                ? app(BranchMap::class)->places($locale)
                : [],
            'callLink' => BranchMap::callLink(),
            'turnstileSiteKey' => $preview ? '' : Cast::string(config('services.turnstile.site_key')),
            'preview' => $preview,
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
