<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Cms\Exceptions\LayoutBlocksRejectedException;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Validation\LayoutSlotInput;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the site's default blocks for the slots around posts, lists and products (LayoutSlots):
 * per slot, one block list per language, `{locale: {"blocks": […]}}`; null removes the slot's
 * default. Slots the call does not name keep what they have. Stored in one setting row
 * (`storefront.layout_blocks`, slot → value), the group the storefront already reads in one query.
 *
 * Every value is checked as a page body is (LayoutSlotInput: a known slot, the site's languages,
 * ValidPageJson in the site's context, the slot's block limit) before anything is written; a refusal
 * is a SettingsChangeRejected with the slot (and language) as its field and the plain-language text
 * in its previous exception. A category may replace a slot (SetCategoryLayoutBlocks); "none" is a
 * category's choice only, never the site's.
 *
 * Behind the settings permission, the one the Giao diện screen is gated by. One transaction
 * (SettingsWrite: the site's settings lock first, waited for a few seconds at most, else a busy
 * refusal); the row is locked, read and written back whole, and RecordsWhoSaved stamps who saved.
 * The library's "in use" rows of the setting (BlockMediaUsage, owner `site_setting`) are written in
 * the same transaction, so a picture or file a slot shows is never reported unused.
 */
final readonly class SetLayoutBlocks
{
    public function __construct(
        private LayoutSlotInput $input,
        private BlockMediaUsage $usage,
    ) {}

    /**
     * @param  array<array-key, mixed>  $slots  slot → {locale: {"blocks": […]}} (null removes it)
     *
     * @throws SettingsChangeRejected
     */
    public function handle(array $slots): void
    {
        Gate::authorize('settings.manage');
        if ($slots === []) {
            return;
        }

        try {
            $checked = $this->input->check($slots, allowNone: false);
        } catch (LayoutBlocksRejectedException $e) {
            throw new SettingsChangeRejected($e->reason, $e->field(), $e);
        }

        SettingsWrite::run(LayoutSlots::SETTING_KEY, function () use ($checked): void {
            $row = SiteSetting::query()
                ->where('group_name', LayoutSlots::SETTING_GROUP)
                ->where('key', LayoutSlots::SETTING_KEY)
                ->lockForUpdate()
                ->first()
                ?? new SiteSetting(['group_name' => LayoutSlots::SETTING_GROUP, 'key' => LayoutSlots::SETTING_KEY, 'value' => []]);

            $value = Cast::arr($row->value);
            foreach ($checked as $slot => $slotValue) {
                if ($slotValue === null) {
                    unset($value[$slot]);
                } else {
                    $value[$slot] = $slotValue;
                }
            }
            // A save that changes nothing leaves the row (and its "last saved" stamp) as it is.
            $row->value = $value;

            if ($row->isDirty() || ! $row->exists) {
                $row->save();
            }

            $this->usage->sync('site_setting', Cast::int($row->getKey()), LayoutSlots::blockLists($row->value));
        });
    }
}
