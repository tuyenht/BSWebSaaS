<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

/**
 * How «Trình dựng» shows a block kind to a person: its name, a short kind chip, a glyph and a tint
 * (design 07 block rows, with the names of designs 27/28 for kinds that board does not draw). A
 * kind not listed here (one registered later) shows the general «Khối» row, never its type key.
 * Holds no state; names come from lang/*.json (`entries.block.*` and `entries.block_chip.*`).
 */
final class BlockKinds
{
    /** The tint of each tone, from the admin tokens (text pairs that pass the contrast check). */
    private const array TONES = [
        'primary' => 'bg-primary-soft text-primary-text',
        'success' => 'bg-success-soft text-success-text',
        'warning' => 'bg-warning-soft text-warning-text',
        'info' => 'bg-info-soft text-info-text',
        'muted' => 'bg-surface-muted text-text-muted',
    ];

    /** Kind => [glyph, tone, chip]. The chip groups kinds the way the design does (several share one). */
    private const array KINDS = [
        'hero' => ['ri-layout-top', 'primary', 'hero'],
        'hero_slider' => ['ri-slideshow', 'primary', 'hero'],
        'page_header' => ['ri-layout-top', 'muted', 'header'],
        'about' => ['ri-layout-left', 'success', 'about'],
        'features' => ['ri-apps-2', 'primary', 'features'],
        'richtext_html' => ['ri-text-block', 'warning', 'text'],
        'richtext' => ['ri-align-left', 'warning', 'text'],
        'cta' => ['ri-megaphone', 'primary', 'cta'],
        'partners' => ['ri-building', 'success', 'partners'],
        'contact' => ['ri-map-pin', 'primary', 'contact'],
        'form' => ['ri-file-list-3', 'primary', 'form'],
        'branches' => ['ri-map-pin', 'success', 'contact'],
        'consult' => ['ri-customer-service', 'primary', 'contact'],
        'latest_posts' => ['ri-article', 'info', 'data'],
        'entry_grid' => ['ri-grid', 'info', 'data'],
        'product_grid' => ['ri-shopping-bag-3', 'info', 'data'],
        'recent_posts' => ['ri-article', 'info', 'data'],
        'category_list' => ['ri-list-check', 'info', 'data'],
        'file_card' => ['ri-file-download', 'muted', 'file'],
        'video' => ['ri-play-circle', 'info', 'media'],
        'gallery' => ['ri-image-2', 'success', 'media'],
        'faq' => ['ri-question-answer', 'warning', 'faq'],
        'testimonial' => ['ri-chat-quote', 'success', 'testimonial'],
        'team' => ['ri-team', 'primary', 'team'],
        'process' => ['ri-route', 'warning', 'process'],
        'pricing' => ['ri-price-tag-3', 'primary', 'pricing'],
        'stats' => ['ri-bar-chart-box', 'primary', 'stats'],
        'quick_links' => ['ri-apps', 'primary', 'links'],
        'reusable_ref' => ['ri-shapes', 'muted', 'other'],
    ];

    /**
     * The row of one block kind.
     *
     * @return array{label: string, chip: string, icon: string, tint: string}
     */
    public static function of(string $type): array
    {
        [$icon, $tone, $chip] = self::KINDS[$type] ?? ['ri-shapes', 'muted', 'other'];

        return [
            'label' => __(isset(self::KINDS[$type]) ? 'entries.block.'.$type : 'entries.block.other'),
            'chip' => __('entries.block_chip.'.$chip),
            'icon' => $icon,
            'tint' => self::TONES[$tone],
        ];
    }

    /**
     * A glimpse of a block's content under its name: its heading, else its text, in plain words.
     *
     * @param  array<string, mixed>  $props
     */
    public static function preview(array $props): string
    {
        foreach (['heading', 'title', 'html', 'paragraphs'] as $prop) {
            $value = $props[$prop] ?? null;
            if (is_array($value)) {
                $value = implode(' ', array_filter($value, is_string(...)));
            }
            if (is_string($value) && trim($value) !== '') {
                $text = EntryProseBody::preview($value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }
}
