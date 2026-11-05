<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\ButtonActions;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * The page editor's "Khi bấm nút" control for every block button (ButtonLink): pick what the button
 * does, then its page, address, part, number, email or file. Each call names the button by its path
 * in $blocks and is ignored unless that path is a button link of the block's schema; a page or a
 * file is taken only when it is this site's (site-scoped lookup), never from the wire as given. The
 * value lands in `<prop>_do`; the older address in the prop itself is cleared then, so the two can
 * never disagree. A problem shows once the field is left (or at once for a page or file that has
 * gone, or an unsafe link).
 *
 * @property list<array<string, mixed>> $blocks
 */
trait EditsButtonActions
{
    /** The button whose kinds list is open. */
    public ?string $buttonOpen = null;

    /** @var array<string, bool> buttons whose field was left (ButtonActions::fid) */
    public array $buttonTouched = [];

    /** @var array<string, bool> buttons whose page is being chosen again */
    public array $buttonSearching = [];

    /** @var array<string, string> what is typed in a button's page search */
    public array $buttonQueries = [];

    /**
     * @var array<string, array{q: string, rows: list<array{ulid: string, title: string, meta: string, icon: string}>}>
     *                                                                                                                  a button's page search results, kept with the text they were found for: the search runs
     *                                                                                                                  again only when that text changes, not on every redraw
     */
    #[Locked]
    public array $buttonResults = [];

    public bool $showButtonFilePicker = false;

    public string $buttonFilePath = '';

    public function toggleButtonKinds(string $path): void
    {
        $this->authorizeEditing();
        if ($this->buttonPath($path)) {
            $this->buttonOpen = $this->buttonOpen === $path ? null : $path;
        }
    }

    public function setButtonKind(string $path, string $kind): void
    {
        $this->authorizeEditing();
        if (! $this->buttonPath($path) || ! in_array($kind, ButtonLink::KINDS, true)) {
            return;
        }

        $current = ButtonActions::current($this->blocks, $path);
        if ($current['value']['k'] !== $kind || $current['auto']) {
            $keep = $current['auto'] && $current['value']['k'] === $kind;
            $this->putButton($path, match (true) {
                $keep && $kind === 'page' => array_filter(['k' => 'page', 'page' => $this->legacyPage(Cast::string($current['value']['path'] ?? ''))]),
                $keep => $this->carried($current['value']),
                default => ['k' => $kind],
            });
        }
        $fid = ButtonActions::fid($path);
        unset($this->buttonTouched[$fid], $this->buttonSearching[$fid]);
        $this->buttonOpen = null;
        $this->focusButton($path, 'cur');
    }

    /** A typed field of the current kind (url, phone, email, subject), saved and checked when left. */
    public function setButtonField(string $path, string $field, string $value): void
    {
        $this->authorizeEditing();
        if (! $this->buttonPath($path)) {
            return;
        }
        $do = $this->carried(ButtonActions::current($this->blocks, $path)['value']);
        $fields = ['url' => ['url'], 'tel' => ['phone'], 'zalo' => ['phone'], 'mail' => ['email', 'subject']][Cast::string($do['k'])] ?? [];
        if (! in_array($field, $fields, true)) {
            return;
        }

        $value = PlainText::line(mb_substr($value, 0, $field === 'subject' ? ButtonLink::SUBJECT_MAX : SafeLink::MAX_LENGTH));
        $do[$field] = trim($value);
        $this->putButton($path, $do);
        if ($field !== 'subject') {
            $this->buttonTouched[ButtonActions::fid($path)] = true;
        }
    }

    public function toggleButtonBlank(string $path): void
    {
        $this->authorizeEditing();
        $do = $this->buttonPath($path) ? $this->carried(ButtonActions::current($this->blocks, $path)['value']) : null;
        if ($do !== null && $do['k'] === 'url') {
            $do['blank'] = ($do['blank'] ?? true) === false;
            $this->putButton($path, $do);
        }
    }

    /** The field was left without a change: its problem shows from now on. */
    public function touchButton(string $path): void
    {
        $this->authorizeEditing();
        if ($this->buttonPath($path)) {
            $this->buttonTouched[ButtonActions::fid($path)] = true;
        }
    }

    public function pickButtonPage(string $path, string $ulid): void
    {
        $this->authorizeEditing();
        if (! $this->buttonPath($path) || ! Entry::query()->where('ulid', $ulid)->whereIn('type', ['page', 'post'])->where('status', 'published')->exists()) {
            return;
        }
        $this->putButton($path, ['k' => 'page', 'page' => $ulid]);
        $fid = ButtonActions::fid($path);
        unset($this->buttonSearching[$fid], $this->buttonQueries[$fid]);
        $this->buttonTouched[$fid] = true;
        $this->focusButton($path, 'pg');
    }

    public function searchButtonPage(string $path): void
    {
        $this->authorizeEditing();
        if ($this->buttonPath($path)) {
            $this->buttonSearching[ButtonActions::fid($path)] = true;
            $this->focusButton($path, 'q');
        }
    }

    public function keepButtonPage(string $path): void
    {
        $this->authorizeEditing();
        if ($this->buttonPath($path)) {
            unset($this->buttonSearching[ButtonActions::fid($path)]);
            $this->focusButton($path, 'pg');
        }
    }

    public function pickButtonAnchor(string $path, string $anchor): void
    {
        $this->authorizeEditing();
        $do = $this->buttonPath($path) ? $this->carried(ButtonActions::current($this->blocks, $path)['value']) : null;
        if ($do !== null && $do['k'] === 'anchor' && ButtonLink::anchor($anchor) !== null) {
            $this->putButton($path, ['k' => 'anchor', 'anchor' => $anchor]);
            $this->buttonTouched[ButtonActions::fid($path)] = true;
        }
    }

    /** "Lấy số của website": the phone of Cài đặt › Thông tin chung. */
    public function useSitePhone(string $path): void
    {
        $this->authorizeEditing();
        $do = $this->buttonPath($path) ? $this->carried(ButtonActions::current($this->blocks, $path)['value']) : null;
        $phone = $this->sitePhone();
        if ($do !== null && in_array($do['k'], ['tel', 'zalo'], true) && $phone !== '') {
            $this->putButton($path, ['k' => $do['k'], 'phone' => $phone]);
            $this->buttonTouched[ButtonActions::fid($path)] = true;
        }
    }

    public function openButtonFilePicker(string $path): void
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);
        if ($this->buttonPath($path)) {
            $this->buttonFilePath = $path;
            $this->showButtonFilePicker = true;
        }
    }

    public function closeButtonFilePicker(): void
    {
        $this->authorizeEditing();
        $this->showButtonFilePicker = false;
        $path = $this->buttonFilePath;
        $this->buttonFilePath = '';
        if ($path !== '') {
            $this->focusButton($path, 'file');
        }
    }

    /** A document of THIS site's library (site-scoped lookup), never a key taken as given. */
    public function pickButtonFile(string $key): void
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);
        $path = $this->buttonFilePath;
        if ($this->buttonPath($path) && Media::query()->where('storage_key', $key)->whereIn('mime', ButtonLink::DOCUMENT_MIMES)->exists()) {
            $this->putButton($path, ['k' => 'file', 'file' => $key]);
            $this->buttonTouched[ButtonActions::fid($path)] = true;
        }
        $this->closeButtonFilePicker();
    }

    public function clearButtonFile(string $path): void
    {
        $this->authorizeEditing();
        if ($this->buttonPath($path)) {
            $this->putButton($path, ['k' => 'file']);
            $this->buttonTouched[ButtonActions::fid($path)] = true;
        }
    }

    /**
     * What the panel draws for every button, and the library's documents while the dialog is open.
     *
     * @param  array<string, array<array-key, mixed>>  $schemas
     * @return array{buttons: array<string, array<string, mixed>>, documents: list<array{key: string, name: string, meta: string, icon: string}>, fileCurrent: string}
     */
    private function buttonPanel(array $schemas): array
    {
        $panel = app(ButtonActions::class);
        $current = $this->showButtonFilePicker && $this->buttonFilePath !== '' ? ButtonActions::current($this->blocks, $this->buttonFilePath)['value'] : [];

        $buttons = $panel->views($this->blocks, $schemas, [
            'open' => $this->buttonOpen,
            'touched' => $this->buttonTouched,
            'searching' => $this->buttonSearching,
            'queries' => $this->buttonQueries,
            'results' => $this->buttonResults,
        ], $this->locale, fn (): string => $this->sitePhone());

        // Keep each open search's results for the next redraw; a closed search drops its own.
        $results = [];
        foreach ($buttons as $view) {
            if (($view['searching'] ?? false) === true && is_array($view['results'] ?? null)) {
                /** @var list<array{ulid: string, title: string, meta: string, icon: string}> $rows ButtonActions::search() */
                $rows = $view['results'];
                $results[Cast::string($view['fid'])] = ['q' => Cast::string($view['query']), 'rows' => $rows];
            }
        }
        $this->buttonResults = $results;

        return [
            'buttons' => $buttons,
            'documents' => $this->showButtonFilePicker && Gate::allows('media.view') ? $panel->documents() : [],
            'fileCurrent' => Cast::string($current['file'] ?? ''),
        ];
    }

    /**
     * Each call re-checks the gate the save path uses (livewire/update skips the route middleware).
     */
    abstract private function authorizeEditing(): void;

    /** Whether $path names a button link of its block's schema (top level or in a list item). */
    private function buttonPath(string $path): bool
    {
        if (preg_match('/\A(\d+)\.props\.(\w+)(?:\.(\d+)\.(\w+))?\z/', $path, $m) !== 1) {
            return false;
        }
        $type = $this->blocks[(int) $m[1]]['type'] ?? null;
        $registry = app(ComponentRegistry::class);
        if (! is_string($type) || ! $registry->has($type)) {
            return false;
        }
        $spec = $registry->schemaFor($type)[$m[2]] ?? null;
        if (! isset($m[3])) {
            return ButtonLink::labelProp($spec) !== null;
        }

        return is_array($spec) && ($spec['type'] ?? null) === 'list' && is_array($spec['of'] ?? null)
            && ButtonLink::labelProp($spec['of'][$m[4] ?? ''] ?? null) !== null
            && is_array(data_get($this->blocks, "{$m[1]}.props.{$m[2]}.{$m[3]}"));
    }

    /**
     * The value with only the fields its kind keeps (an older address sorted into a page holds a
     * `path`, which is no stored field).
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function carried(array $value): array
    {
        $keep = ['k' => true, 'page' => true, 'url' => true, 'blank' => true, 'anchor' => true, 'phone' => true, 'email' => true, 'subject' => true, 'file' => true];
        $value = array_intersect_key($value, $keep);

        return ButtonLink::problem($value) === 'kind' ? ['k' => $value['k']] : $value;
    }

    /**
     * @param  array<string, mixed>  $do
     */
    private function putButton(string $path, array $do): void
    {
        $blocks = $this->blocks;
        data_set($blocks, $path.ButtonLink::SUFFIX, $do);
        data_set($blocks, $path, null);
        /** @var list<array<string, mixed>> $blocks */
        $this->blocks = $blocks;
    }

    /** The ulid of the page an older site address opens in the editor's language, or ''. */
    private function legacyPage(string $address): string
    {
        $route = $address === '' ? null : SiteRoute::query()->where('locale', $this->locale)->where('path', $address)->whereNotNull('entry_id')->first(['entry_id']);
        $ulid = $route === null ? null : Entry::query()->whereKey($route->entry_id)->value('ulid');

        return is_string($ulid) ? $ulid : '';
    }

    private function sitePhone(): string
    {
        $identity = app(SiteIdentity::class)->for(app(SiteLocale::class)->default()) ?? [];
        $phone = trim(Cast::string($identity['phone'] ?? ''));

        return $phone !== '' ? $phone : trim(Cast::string($identity['hotline'] ?? ''));
    }

    /** Put focus back on a part of the button's control after the redraw (the clicked part is gone). */
    private function focusButton(string $path, string $part): void
    {
        $id = 'btn-act-'.ButtonActions::fid($path).'-'.$part;
        $this->js('setTimeout(() => document.getElementById('.json_encode($id).')?.focus(), 30)');
    }
}
