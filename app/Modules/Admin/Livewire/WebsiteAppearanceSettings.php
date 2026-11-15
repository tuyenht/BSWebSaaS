<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\Settings\ResetSettingsSection;
use App\Modules\Admin\Support\Settings\SetBrandImages;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\Settings\SettingsStamp;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Giao diện website": the website's logo, its logo on a dark background and its favicon, each one
 * picture from the shared Image library (WithMediaLibraryPicker, which re-checks media.view and only
 * hands over this site's live pictures). A pick is a media key in the draft, counted and discarded
 * like any field, and saved in one step through SetBrandImages, which looks every key up again in
 * the site's media scope: another site's picture, one in the trash or a file that is not a picture
 * is refused under its slot and nothing is written. "Gỡ ảnh" empties a slot (the picture stays in
 * the library). A logo or favicon that is still the older general.brand URL shows in its slot, so
 * it can be replaced or removed here.
 *
 * The row above the fields says when the section was last saved and by whom (SettingsStamp, the
 * site profile row) and offers "Khôi phục mặc định": the three images are emptied
 * (ResetSettingsSection, section `appearance`), with "Hoàn tác" in the message. The share image
 * stays in "Google & mạng xã hội", which this page links to.
 *
 * Every request is settings.manage-gated (boot) and so is every action.
 */
#[Layout('admin::layouts.app')]
final class WebsiteAppearanceSettings extends Component
{
    use WithMediaLibraryPicker {
        openImagePicker as private openLibrary;
        togglePickerItem as private toggleLibraryItem;
    }

    /** The slots of this page, in the page's order (SiteProfile::IMAGE_COLUMNS keys). */
    public const array SLOTS = ['logo', 'logo_dark', 'favicon'];

    /** The slots whose older URL general.brand may still hold. */
    private const array LEGACY_SLOTS = ['logo', 'favicon'];

    /**
     * A slot's draft value while it is still the older URL general.brand holds (never a media key,
     * so a save that sends it for a slot whose saved value is not that is refused).
     */
    public const string OLD_IMAGE = '@old';

    /** @var array{logo: string, logo_dark: string, favicon: string} the draft: slot → media key ('' = none) */
    public array $images = ['logo' => '', 'logo_dark' => '', 'favicon' => ''];

    /** The slot the Image library was opened for (set on the server only). */
    #[Locked]
    public string $pickerFor = '';

    /** @var array{logo: string, logo_dark: string, favicon: string} the draft as loaded or last saved */
    #[Locked]
    public array $saved = ['logo' => '', 'logo_dark' => '', 'favicon' => ''];

    /** @var array<string, string> slot → the older general.brand URL the site still shows for it */
    #[Locked]
    public array $legacy = [];

    /**
     * What "Khôi phục mặc định" removed, for "Hoàn tác" (kept on the server side of the component).
     *
     * @var array<array-key, mixed>|null
     */
    #[Locked]
    public ?array $undo = null;

    public string $toast = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->apply($this->load());
    }

    /** "Đổi ảnh" / the empty box: the library opens on pictures only, for that slot. */
    #[Authorize('settings.manage')]
    public function pickFor(string $slot): void
    {
        if (! in_array($slot, self::SLOTS, true)) {
            return;
        }
        $this->pickerFor = $slot;
        $this->openImagePicker();
    }

    /** The library opens on pictures only: a slot takes nothing else. */
    public function openImagePicker(string $mode = 'gallery'): void
    {
        $this->openLibrary('gallery');
        $this->pickerType = 'image';
    }

    /** A slot holds one image: choosing another in the library replaces the one chosen before. */
    public function togglePickerItem(int $mediaId): void
    {
        $this->toggleLibraryItem($mediaId);
        if (! $this->pickerTrash && count($this->pickerSelected) > 1) {
            $this->pickerSelected = [$mediaId];
        }
    }

    /** "Gỡ ảnh": the slot is emptied in the draft; the picture stays in the library. */
    #[Authorize('settings.manage')]
    public function removeImage(string $slot): void
    {
        if (! in_array($slot, self::SLOTS, true)) {
            return;
        }
        $this->images[$slot] = '';
        $this->resetErrorBag('images.'.$slot);
    }

    #[Authorize('settings.manage')]
    public function save(): void
    {
        $this->resetErrorBag();
        $draft = $this->draft();
        if ($draft === null) {
            $this->addError('images', __('admin.gs.err_tampered'));
            $this->notify(__('admin.gs.toast_invalid', ['count' => 1]), 'error');

            return;
        }

        $picks = [];
        foreach ($draft as $slot => $key) {
            if ($key !== $this->saved[$slot]) {
                $picks[$slot] = $key;
            }
        }

        try {
            // One step: a key the library refuses (another site's, in the trash, not a picture, or
            // the older-URL marker on a slot that does not hold one) writes no slot at all.
            app(SetBrandImages::class)->handle($picks);
        } catch (SettingsChangeRejected $e) {
            if ($e->reason === 'busy') {
                $this->notify(__('admin.settings.busy'), 'error');

                return;
            }
            if ($e->reason !== 'image_invalid' || ! in_array($e->field, self::SLOTS, true)) {
                throw $e;
            }
            $this->addError('images.'.$e->field, __('admin.seoset.err_share_image'));
            $this->notify(__('admin.gs.toast_invalid', ['count' => 1]), 'error');
            $this->dispatch('look-first-error');

            return;
        }

        $this->apply($this->load());
        $this->notify(__('admin.look.toast_saved'));
    }

    /** "Bỏ thay đổi": back to the saved images. */
    #[Authorize('settings.manage')]
    public function resetChanges(): void
    {
        $this->apply($this->load());
        $this->resetErrorBag();
        $this->notify(__('admin.gs.toast_reset'));
    }

    /** "Khôi phục mặc định": the three images are emptied at once; the message offers "Hoàn tác". */
    #[Authorize('settings.manage')]
    public function restoreDefaults(): void
    {
        try {
            $undo = app(ResetSettingsSection::class)->reset('appearance');
        } catch (SettingsChangeRejected $e) {
            if ($e->reason !== 'busy') {
                throw $e;
            }
            $this->notify(__('admin.settings.busy'), 'error');

            return;
        }

        $this->apply($this->load());
        $this->resetErrorBag();
        $this->notify(__('admin.set.restored'));
        $this->undo = $undo;
    }

    /** "Hoàn tác" after "Khôi phục mặc định": the images come back (one since purged stays empty). */
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
            $this->notify(__('admin.settings.busy'), 'error');
            $this->undo = null;

            return;
        }

        $this->apply($this->load());
        $this->notify(__('admin.set.undone'));
    }

    #[Authorize('settings.manage')]
    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
            $this->undo = null;
        }
    }

    public function render(): View
    {
        $draft = $this->draft() ?? $this->saved;
        $keys = array_values(array_filter($draft, static fn (string $key): bool => $key !== '' && $key !== self::OLD_IMAGE));
        // The pictures the draft names, in one query: only live pictures of this site resolve.
        $media = $keys === [] ? collect() : Media::query()->whereIn('storage_key', $keys)->where('mime', 'like', 'image/%')
            ->orderBy('id')->get(['id', 'storage_key', 'slug', 'original_filename', 'width', 'height'])->keyBy('storage_key');
        $urls = app(MediaUrlResolver::class);

        $slots = [];
        foreach (self::SLOTS as $slot) {
            $key = $draft[$slot];
            $picked = $key !== '' ? $media->get($key) : null;
            $old = $key === self::OLD_IMAGE ? ($this->legacy[$slot] ?? null) : null;
            $slots[$slot] = match (true) {
                $picked instanceof Media => [
                    'thumb' => $urls->url($picked->storage_key, 320),
                    'name' => $picked->original_filename ?? $picked->slug ?? basename($picked->storage_key),
                    'meta' => $picked->width !== null && $picked->height !== null
                        ? Cast::string(__('admin.seoset.share_size', ['w' => $picked->width, 'h' => $picked->height]))
                        : Cast::string(__('admin.seoset.share_from_library')),
                ],
                $old !== null => [
                    'thumb' => $old,
                    'name' => rawurldecode(basename(Cast::string(parse_url($old, PHP_URL_PATH)))) ?: $old,
                    'meta' => Cast::string(__('admin.seoset.share_old')),
                ],
                default => null,
            };
        }

        $view = view('admin::livewire.website-appearance-settings', [
            'pictures' => $slots,
            'changes' => $this->changeCount(),
            'canPick' => Gate::allows('media.view'),
            'stamp' => $this->stampText(),
        ]);
        $view->title(__('admin.website_look'));

        return $view;
    }

    /**
     * The library's pick goes into the slot it was opened for, as its media key. A file that is not
     * a picture is refused with a message under the slot.
     */
    protected function addGalleryImage(int $mediaId): void
    {
        $this->showImagePicker = false;
        $slot = $this->pickerFor;
        if (! in_array($slot, self::SLOTS, true)) {
            return;
        }
        $this->resetErrorBag('images.'.$slot);
        $media = Media::query()->whereKey($mediaId)->first(['id', 'storage_key', 'mime']);
        if ($media === null) {
            return;
        }
        if (! str_starts_with($media->mime, 'image/')) {
            $this->addError('images.'.$slot, __('admin.look.err_type'));

            return;
        }
        $this->images[$slot] = $media->storage_key;
    }

    /**
     * The saved images by their media keys (only a live picture of the site counts), and the older
     * general.brand URL of a slot that has no image of its own yet.
     *
     * @return array{images: array{logo: string, logo_dark: string, favicon: string}, legacy: array<string, string>}
     */
    private function load(): array
    {
        $columns = array_map(static fn (string $slot): string => SiteProfile::IMAGE_COLUMNS[$slot], self::SLOTS);
        $profile = SiteProfile::query()->first($columns);
        $ids = [];
        foreach (self::SLOTS as $slot) {
            $id = $profile?->getAttribute(SiteProfile::IMAGE_COLUMNS[$slot]);
            if (is_int($id)) {
                $ids[$slot] = $id;
            }
        }
        $keys = $ids === [] ? [] : Media::query()->whereIn('id', array_values($ids))->where('mime', 'like', 'image/%')
            ->pluck('storage_key', 'id')->all();

        $legacyUrls = Cast::arr(app(SiteIdentity::class)->for(app(SiteLocale::class)->default())['legacy_images'] ?? null);
        $images = ['logo' => '', 'logo_dark' => '', 'favicon' => ''];
        $legacy = [];
        foreach (self::SLOTS as $slot) {
            $key = isset($ids[$slot]) ? ($keys[$ids[$slot]] ?? null) : null;
            if (is_string($key) && $key !== '') {
                $images[$slot] = $key;

                continue;
            }
            $old = in_array($slot, self::LEGACY_SLOTS, true) ? ($legacyUrls[$slot] ?? null) : null;
            if (is_string($old) && preg_match('~\Ahttps?://~i', $old) === 1) {
                $images[$slot] = self::OLD_IMAGE;
                $legacy[$slot] = $old;
            }
        }

        return ['images' => $images, 'legacy' => $legacy];
    }

    /** @param  array{images: array{logo: string, logo_dark: string, favicon: string}, legacy: array<string, string>}  $loaded */
    private function apply(array $loaded): void
    {
        $this->images = $loaded['images'];
        $this->saved = $loaded['images'];
        $this->legacy = $loaded['legacy'];
        $this->pickerFor = '';
        $this->undo = null;
    }

    /**
     * The draft as sent, checked for shape: exactly the page's slots, each a string; the older-URL
     * marker only where the saved value is that marker. Null when it was tampered with.
     *
     * @return array{logo: string, logo_dark: string, favicon: string}|null
     */
    private function draft(): ?array
    {
        /** @var array<mixed> $sent */
        $sent = $this->images;
        if (array_diff(array_keys($sent), self::SLOTS) !== []) {
            return null;
        }
        $draft = ['logo' => '', 'logo_dark' => '', 'favicon' => ''];
        foreach (self::SLOTS as $slot) {
            $value = $sent[$slot] ?? '';
            if (! is_string($value)) {
                return null;
            }
            $value = trim($value);
            if ($value === self::OLD_IMAGE && $this->saved[$slot] !== self::OLD_IMAGE) {
                return null;
            }
            $draft[$slot] = $value;
        }

        return $draft;
    }

    /** How many slots differ from the saved images. */
    private function changeCount(): int
    {
        $draft = $this->draft();
        if ($draft === null) {
            return count(self::SLOTS);
        }
        $n = 0;
        foreach (self::SLOTS as $slot) {
            $n += $draft[$slot] !== $this->saved[$slot] ? 1 : 0;
        }

        return $n;
    }

    /**
     * "Cập nhật lần cuối 14:05 · Ngọc Anh" (today) or "… 12/11/2026 · …" (another day, in the site's
     * date format and time zone); null when nothing of the section was ever saved.
     */
    private function stampText(): ?string
    {
        $stamp = app(SettingsStamp::class)->forSection('appearance');
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

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
        $this->undo = null;
    }
}
