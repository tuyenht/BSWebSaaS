<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Settings\ResetSettingsSection;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\Settings\SettingsStamp;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Livewire\Attributes\Authorize;

/**
 * The board's line above a settings section's fields: «Cập nhật lần cuối 14:05 · Ngọc Anh» and
 * «Khôi phục mặc định» (in the "⋮" on phones), whose message offers «Hoàn tác». What a section
 * stores (the stamp) is SettingsSections' entry settingsSection(); what its reset removes is
 * restoreSection(), the same entry unless the host names a narrower one. The host re-reads its
 * saved values after either step. Needs ShowsSettingsToast for the message.
 */
trait RestoresSettingsSection
{
    /** The section's key in SettingsSections. */
    abstract protected function settingsSection(): string;

    /** The SettingsSections entry «Khôi phục mặc định» resets: by default the whole section. */
    protected function restoreSection(): string
    {
        return $this->settingsSection();
    }

    /** Put the saved values back into the form, and clear their errors (after a reset or its undo). */
    abstract protected function reloadSaved(): void;

    #[Authorize('settings.manage')]
    public function restoreDefaults(): void
    {
        try {
            $undo = app(ResetSettingsSection::class)->reset($this->restoreSection());
        } catch (SettingsChangeRejected $e) {
            if ($e->reason !== 'busy') {
                throw $e;
            }
            $this->notify(__('admin.settings.busy'), 'error');

            return;
        }

        $this->reloadSaved();
        $this->notify(__('admin.set.restored'));
        $this->undo = $undo;
    }

    #[Authorize('settings.manage')]
    public function undoRestore(): void
    {
        if ($this->undo === null) {
            return;
        }
        try {
            app(ResetSettingsSection::class)->undo($this->undo);
        } catch (SettingsChangeRejected $e) {
            if ($e->reason !== 'busy') {
                throw $e;
            }
            $payload = $this->undo;
            $this->notify(__('admin.settings.busy'), 'error');
            $this->undo = $payload;   // still offered: the next try may find the lock free

            return;
        }

        $this->reloadSaved();
        $this->notify(__('admin.set.undone'));
    }

    /**
     * "Cập nhật lần cuối 14:05 · Ngọc Anh" (today) or "… 12/11/2026 · …" (another day, in the site's
     * date format and time zone); null when nothing of the section was ever saved.
     */
    private function stampText(): ?string
    {
        $stamp = app(SettingsStamp::class)->forSection($this->settingsSection());
        if ($stamp === null) {
            return null;
        }
        $time = app(SiteTime::class);
        $local = $time->forDisplay($stamp['at']);
        $when = $local !== null && $local->isSameDay($time->now()) ? $local->format('H:i') : $time->day($stamp['at'], app()->getLocale());

        return $stamp['by'] !== null
            ? Cast::string(__('admin.set.stamp', ['when' => $when, 'name' => $stamp['by']]))
            : Cast::string(__('admin.set.stamp_anon', ['when' => $when]));
    }
}
