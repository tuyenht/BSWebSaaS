<?php

declare(strict_types=1);

namespace App\Modules\Cms\Validation;

use App\Modules\Cms\Casts\SanitizedPageBody;
use App\Modules\Cms\Exceptions\LayoutBlocksRejectedException;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Support\Facades\Validator;

/**
 * The one check both block-slot stores run before they write (the site's default and a category's
 * own choice): every slot named is one of LayoutSlots, every language key is one of the site's
 * languages, and each language's `{"blocks": […]}` passes the same check as a page body, plus the
 * block types only a slot may hold (ValidPageJson::forSlots), in the current site's context, so a
 * button to another site's page or file is refused, within the slot's own block limit. What passes comes back cleaned the way a page body is
 * stored (every formatted-text prop through the sanitizer, SanitizedPageBody) and reduced to
 * `{locale: {"blocks": […]}}` — nothing else the caller sent is kept.
 *
 * A slot's value may be null (no value of its own: the site shows nothing there, a category follows
 * the site), and for a category LayoutSlots::NONE (hidden in every language). An empty language map
 * reads as null. Runs inside the tenant context; holds no state.
 */
final readonly class LayoutSlotInput
{
    public function __construct(
        private ValidPageJson $pageJson,
        private SiteLocale $locales,
    ) {}

    /**
     * @param  array<array-key, mixed>  $slots  slot → value
     * @return array<string, array<string, array{blocks: list<mixed>}>|string|null> slot → checked value
     *
     * @throws LayoutBlocksRejectedException
     */
    public function check(array $slots, bool $allowNone): array
    {
        $checked = [];
        foreach ($slots as $slot => $value) {
            $slot = (string) $slot;
            if (! LayoutSlots::has($slot)) {
                throw new LayoutBlocksRejectedException('slot_invalid', $slot, null, __('layout_slots.err_slot_invalid'));
            }
            $checked[$slot] = $this->one($slot, $value, $allowNone);
        }

        return $checked;
    }

    /**
     * @return array<string, array{blocks: list<mixed>}>|string|null
     *
     * @throws LayoutBlocksRejectedException
     */
    private function one(string $slot, mixed $value, bool $allowNone): array|string|null
    {
        if ($value === null) {
            return null;
        }
        if ($allowNone && $value === LayoutSlots::NONE) {
            return LayoutSlots::NONE;
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new LayoutBlocksRejectedException('blocks_invalid', $slot, null, __('entries.err_blocks_invalid'));
        }

        $enabled = $this->locales->enabled();
        $checked = [];
        foreach ($value as $locale => $text) {
            $locale = (string) $locale;
            if (! in_array($locale, $enabled, true)) {
                throw new LayoutBlocksRejectedException('locale_invalid', $slot, $locale, __('layout_slots.err_locale_invalid'));
            }

            $blocks = is_array($text) ? ($text['blocks'] ?? null) : null;
            if (! is_array($blocks) || ! array_is_list($blocks)) {
                throw new LayoutBlocksRejectedException('blocks_invalid', $slot, $locale, __('entries.err_blocks_invalid'));
            }

            $max = LayoutSlots::maxBlocks($slot);
            if ($max !== null && count($blocks) > $max) {
                throw new LayoutBlocksRejectedException('blocks_too_many', $slot, $locale, __('layout_slots.err_too_many', ['max' => $max]));
            }

            $validator = Validator::make(['blocks' => $blocks], ['blocks' => [$this->pageJson->forSlots()]]);
            if ($validator->fails()) {
                throw new LayoutBlocksRejectedException('blocks_invalid', $slot, $locale, (string) $validator->errors()->first('blocks'));
            }

            $clean = SanitizedPageBody::cleanBody(['blocks' => $blocks])['blocks'];
            $checked[$locale] = ['blocks' => is_array($clean) ? array_values($clean) : []];
        }

        return $checked === [] ? null : $checked;
    }
}
