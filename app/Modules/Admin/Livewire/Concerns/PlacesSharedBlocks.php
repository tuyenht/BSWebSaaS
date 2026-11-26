<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Entries\SharedBlockPicker;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Theme\Components\BlockReference;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Renderless;

/**
 * «Khối dùng chung» in «Trình dựng» of a page or post body (board 27): «Chèn khối dùng chung» lists the
 * website's shared blocks (SharedBlockPicker::choices, read when the dialog opens, published first,
 * drafts too: a draft shows on the website once published) and places the one picked at the end of
 * this language's layout as `{"type": "reusable_ref", "props": {"block": "<ulid>"}}`, nothing else.
 * A layout holds at most BlockReference::LIST_MAX of them. Nothing is saved: the body is saved as any
 * other edit, where ValidPageJson checks the block is this website's again.
 *
 * Offered where the plan has the block library (`blocks` module) to someone who may see it
 * (ContentBlockPolicy::viewAny: whoever may write a page may place a block); every call asks
 * authorizeEditing() first, as every endpoint of the editor does.
 *
 * @property list<array<string, mixed>> $blocks
 * @property bool $prose
 * @property string $locale
 * @property int|null $openBlock
 */
trait PlacesSharedBlocks
{
    /**
     * The «Chèn khối dùng chung» list, with what each row says, and whether this layout already
     * holds the most shared blocks it may.
     *
     * @return array{ok: true, cap: bool, rows: list<array{ulid: string, name: string, region: string, draft: bool, meta: string, onPage: bool, loc: string|null, key: string}>}
     */
    #[Renderless]
    public function sharedBlockChoices(): array
    {
        $this->authorizeSharedPlacing();
        $picker = app(SharedBlockPicker::class);
        $onPage = array_flip(BlockReference::ulids($this->prose ? [] : $this->blocks));
        $rows = [];
        foreach ($picker->choices() as $row) {
            $rows[] = [
                'ulid' => $row['ulid'],
                'name' => $row['name'],
                'region' => $row['region'],
                'draft' => $row['draft'],
                'meta' => $this->sharedMeta($row['key'], $row['locales'], $row['places']),
                'onPage' => isset($onPage[$row['ulid']]),
                'loc' => $this->sharedFallbackLine($picker, $row['locales'], 'row'),
                'key' => $row['key'],
            ];
        }

        return ['ok' => true, 'cap' => $this->sharedCapReached(), 'rows' => $rows];
    }

    /**
     * A row of «Chèn khối dùng chung»: the block goes at the end of this language's layout (a written
     * body becomes the layout's first block), its card opened; returns its position, for the browser to
     * move the focus there. A ulid that is not one of this website's blocks (another website's, one
     * deleted meanwhile, made up) places nothing, nor does a layout that already holds the most.
     */
    public function insertSharedBlock(mixed $ulid): ?int
    {
        $this->authorizeSharedPlacing();
        if (! is_string($ulid) || strlen($ulid) !== 26 || preg_match(SectionProps::ULID_PATTERN, $ulid) !== 1) {
            $this->addError('blocks', __('entries.ref.gone_pick'));

            return null;
        }
        if ($this->sharedCapReached()) {
            $this->addError('blocks', __('entries.err_block_refs_too_many', ['max' => BlockReference::LIST_MAX]));

            return null;
        }
        $block = ContentBlock::query()->where('ulid', strtoupper($ulid))->first(['id', 'ulid', 'name']);
        if (! $block instanceof ContentBlock) {
            $this->addError('blocks', __('entries.ref.gone_pick'));

            return null;
        }

        $this->resetErrorBag('blocks');
        if ($this->prose) {
            $this->leaveProse();
        }
        $this->blocks[] = ['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => strtoupper($block->ulid)]];
        $this->openBlock = array_key_last($this->blocks);
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ref.placed', ['name' => $block->name])));

        return $this->openBlock;
    }

    /** Whether «Khối dùng chung» is offered on this editor at all (the plan's module, and the right to see blocks). */
    private function sharedBlocksOffered(): bool
    {
        return in_array('blocks', app(Entitlement::class)->entitledModuleKeys(), true) && Gate::allows('viewAny', ContentBlock::class);
    }

    private function authorizeSharedPlacing(): void
    {
        $this->authorizeEditing();
        abort_unless($this->sharedBlocksOffered(), 403);
    }

    private function sharedCapReached(): bool
    {
        return ! $this->prose && SharedBlockPicker::referenceCount($this->blocks) >= BlockReference::LIST_MAX;
    }

    /**
     * What the cards of the shared blocks this layout places say, by ulid (a block the website no longer
     * has is absent: its card says it is gone). No read when the layout places none.
     *
     * @return array<string, array{name: string, key: string, draft: bool, langs: string, uses: string, href: string|null, loc: string|null}>
     */
    private function placedSharedBlocks(): array
    {
        $ulids = $this->prose ? [] : BlockReference::ulids($this->blocks);
        if ($ulids === []) {
            return [];
        }
        $picker = app(SharedBlockPicker::class);
        $canOpen = $this->sharedBlocksOffered() && Gate::allows('create', ContentBlock::class);
        $default = app(SiteLocale::class)->default();
        $out = [];
        foreach ($picker->placed($ulids) as $ulid => $row) {
            $out[$ulid] = [
                'name' => $row['name'],
                'key' => $row['key'],
                'draft' => $row['draft'],
                'langs' => implode(', ', array_map(strtoupper(...), $row['locales'])),
                'uses' => Cast::string(__('entries.ref.uses', ['places' => trans_choice('blocks.n_places', $row['places'], ['n' => $row['places']])])),
                'href' => $canOpen ? route('admin.blocks.edit', ['ulid' => $ulid] + ($this->locale !== $default ? ['locale' => $this->locale] : [])) : null,
                'loc' => $this->sharedFallbackLine($picker, $row['locales'], 'card'),
            ];
        }

        return $out;
    }

    /**
     * "Mã: lien-he · VI, EN · Dùng ở 3 nơi" under a row's name.
     *
     * @param  list<string>  $locales
     */
    private function sharedMeta(string $key, array $locales, int $places): string
    {
        $parts = [Cast::string(__('entries.ref.meta_key', ['key' => $key]))];
        if ($locales !== []) {
            $parts[] = implode(', ', array_map(strtoupper(...), $locales));
        }
        $parts[] = Cast::string(__('entries.ref.uses', ['places' => trans_choice('blocks.n_places', $places, ['n' => $places])]));

        return implode(' · ', $parts);
    }

    /**
     * The line saying what a visitor of this page's language sees of a block without that language
     * (the website's default language, or nothing), or null when the block has it.
     *
     * @param  list<string>  $locales
     * @param  'row'|'card'  $where
     */
    private function sharedFallbackLine(SharedBlockPicker $picker, array $locales, string $where): ?string
    {
        $fallback = $picker->fallback($locales, $this->locale);
        if ($fallback === null) {
            return null;
        }

        return Cast::string(__("entries.ref.loc_{$where}_{$fallback}", ['lang' => Locales::label($this->locale), 'default' => $picker->defaultLabel()]));
    }

    abstract private function authorizeEditing(): void;

    abstract private function leaveProse(): void;
}
