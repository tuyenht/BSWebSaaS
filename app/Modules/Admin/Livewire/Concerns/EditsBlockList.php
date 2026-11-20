<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\BlockKinds;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Shared\Support\Cast;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Theme\Components\StyleProps;
use App\Modules\Theme\Theming\ThemeResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The block list of «Trình dựng», shared by every screen that lays content out in blocks (a page or
 * post body, a reusable block): one block's content form open at a time, the order, the "Trình
 * bày" panel, list props, the image picker of a block field, and what the view needs to draw them
 * (kinds, looks, colour sets, image previews, gone products). Adding and removing a block stay with
 * each screen, which knows what else the body holds.
 *
 * Every public method asks the screen's authorizeEditing() first: the livewire/update endpoint
 * skips the route.
 *
 * @property list<array<string, mixed>> $blocks
 */
trait EditsBlockList
{
    /** Block image picker: the target prop path inside $blocks (e.g. "0.props.image_url" or
     *  "0.props.logos.1.image_url"), held while the shared «Chọn ảnh» dialog is open for a block
     *  image field (the host uses WithMediaLibraryPicker; its single pick lands in placeBlockImage). */
    public string $blockImagePickerPath = '';

    /** The block whose content form is open under its row in «Trình dựng» (one at a time). */
    public ?int $openBlock = null;

    /**
     * Blocks whose "Trình bày" panel shows the top and bottom spacing apart, by index (editor state
     * only, never saved; a block whose two steps differ shows them apart anyway).
     *
     * @var array<int, bool>
     */
    public array $lookSplit = [];

    /** «Sửa nội dung» on a block row: opens its content form, or closes it when open. */
    public function toggleBlock(int $index): void
    {
        $this->authorizeEditing();
        $this->openBlock = $this->openBlock === $index || ! array_key_exists($index, $this->blocks) ? null : $index;
    }

    public function moveUp(int $index): void
    {
        $this->authorizeEditing();
        if ($index <= 0 || ! array_key_exists($index, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index - 1], $blocks[$index]] = [$blocks[$index], $blocks[$index - 1]];
        $this->blocks = $blocks;
        $this->lookSplit = [];
        $this->followMove($index, $index - 1);
    }

    public function moveDown(int $index): void
    {
        $this->authorizeEditing();
        if (! array_key_exists($index + 1, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index], $blocks[$index + 1]] = [$blocks[$index + 1], $blocks[$index]];
        $this->blocks = $blocks;
        $this->lookSplit = [];
        $this->followMove($index, $index + 1);
    }

    /** The open content form follows its block when two blocks swap places. */
    private function followMove(int $from, int $to): void
    {
        $this->openBlock = match ($this->openBlock) {
            $from => $to,
            $to => $from,
            default => $this->openBlock,
        };
    }

    /**
     * One choice on a block's "Trình bày" panel. Only a value listed in StyleProps is kept, and only
     * where the block's schema takes the look prop (page_header's own `overlay` is not this one). A
     * colour set paints the band (under a photo it waits until the photo is removed); `spacing` sets
     * the top and bottom step together.
     */
    public function setLook(int $index, string $prop, string $value): void
    {
        $this->authorizeEditing();
        if (! in_array($value, StyleProps::CHOICES[$prop] ?? [], true) || ! $this->takesLook($index, $prop === 'spacing' ? 'spacing_top' : $prop)) {
            return;
        }

        $props = $this->lookProps($index);
        if ($prop === 'scheme') {
            $props['scheme'] = $value;
            $props['bg'] = ($props['bg'] ?? null) === 'image' ? 'image' : 'scheme';
        } elseif ($prop === 'spacing') {
            $props['spacing_top'] = $value;
            $props['spacing_bottom'] = $value;
        } else {
            $props[$prop] = $value;
        }
        $this->setBlockProp("{$index}.props", $props);
    }

    /** Show the top and bottom spacing apart, or join them again (bottom takes the top step). */
    public function toggleLookSplit(int $index): void
    {
        $this->authorizeEditing();
        if (! array_key_exists($index, $this->blocks)) {
            return;
        }

        if ($this->spacingSplit($index)) {
            unset($this->lookSplit[$index]);
            $props = $this->lookProps($index);
            $top = $props['spacing_top'] ?? null;
            if (is_string($top)) {
                $props['spacing_bottom'] = $top;
            } else {
                unset($props['spacing_bottom']);
            }
            $this->setBlockProp("{$index}.props", $props);
        } else {
            $this->lookSplit[$index] = true;
        }
    }

    /** Take the background photo away: the band goes back to the chosen colour set, if any. */
    public function removeLookImage(int $index): void
    {
        $this->authorizeEditing();
        if (! $this->takesLookPhoto($index)) {
            return;
        }

        $props = $this->lookProps($index);
        unset($props['bg_image'], $props['overlay']);
        $props['bg'] = isset($props['scheme']) ? 'scheme' : 'none';
        $this->setBlockProp("{$index}.props", $props);
    }

    /** Whether the block at $index takes this look prop from the panel (not a same-named prop of its own). */
    private function takesLook(int $index, string $key): bool
    {
        $type = $this->blocks[$index]['type'] ?? null;
        $registry = app(ComponentRegistry::class);

        return is_string($type) && $registry->has($type)
            && ($registry->schemaFor($type)[$key] ?? null) === (StyleProps::SCHEMA[$key] ?? false);
    }

    /**
     * Whether the panel's background photo applies: a block with a photo and darkening of its own
     * (page_header, hero_slider) keeps those, and the panel offers no second photo.
     */
    private function takesLookPhoto(int $index): bool
    {
        return $this->takesLook($index, 'bg_image') && $this->takesLook($index, 'overlay');
    }

    /** $anchor, or it with the first free number, when another block or the layout already uses it. */
    private function freeAnchor(string $anchor, int $index): string
    {
        $taken = StyleProps::LAYOUT_IDS;
        foreach (array_keys($this->blocks) as $other) {
            $used = $this->lookProps($other)['anchor_id'] ?? null;
            if ($other !== $index && is_string($used)) {
                $taken[] = $used;
            }
        }

        $candidate = $anchor;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $suffix = '-'.$n;
            $candidate = rtrim(substr($anchor, 0, 64 - strlen($suffix)), '-').$suffix;
        }

        return $candidate;
    }

    private function spacingSplit(int $index): bool
    {
        $props = $this->lookProps($index);

        return ($this->lookSplit[$index] ?? false) || ($props['spacing_top'] ?? null) !== ($props['spacing_bottom'] ?? null);
    }

    /**
     * A block's props (an empty list for a missing block).
     *
     * @return array<array-key, mixed>
     */
    private function lookProps(int $index): array
    {
        $props = $this->blocks[$index]['props'] ?? null;

        return is_array($props) ? $props : [];
    }

    /** Append a blank item (shaped by the list's `of` schema) to a list-typed prop. */
    public function addItem(int $index, string $key): void
    {
        $this->authorizeEditing();
        $list = $this->resolveList($index, $key);
        if ($list === null) {
            return;
        }

        // Clear a stale save-time error so it doesn't linger over valid edits (mirrors addBlock).
        $this->resetErrorBag('blocks');
        $items = $list['items'];
        $items[] = $list['blank'];
        $this->putItems($index, $key, $items);
    }

    public function removeItem(int $index, string $key, int $itemIndex): void
    {
        $this->authorizeEditing();
        $list = $this->resolveList($index, $key);
        if ($list === null || ! array_key_exists($itemIndex, $list['items'])) {
            return;
        }

        $items = $list['items'];
        array_splice($items, $itemIndex, 1);
        $this->putItems($index, $key, $items);
    }

    public function moveItemUp(int $index, string $key, int $itemIndex): void
    {
        $this->authorizeEditing();
        $this->swapItems($index, $key, $itemIndex, $itemIndex - 1);
    }

    public function moveItemDown(int $index, string $key, int $itemIndex): void
    {
        $this->authorizeEditing();
        $this->swapItems($index, $key, $itemIndex, $itemIndex + 1);
    }

    public function openBlockImagePicker(string $path): void
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);

        $this->openImagePicker('single');
        $this->blockImagePickerPath = $path;
    }

    /**
     * Set a block image prop to the picture picked in the shared dialog — a live media of THIS site
     * (the picker re-resolves the id through the site's scope, never the wire's word); a file that is
     * not a picture is not taken. Content references media by id (ADR-17), not a URL.
     */
    protected function placeBlockImage(Media $media): void
    {
        $this->authorizeEditing();
        abort_unless(Gate::allows('media.view'), 403);

        $path = $this->blockImagePickerPath;
        $this->blockImagePickerPath = '';
        if ($path === '' || ! str_starts_with($media->mime, 'image/')) {
            return;
        }
        $this->setBlockProp($path, $media->id);
        if (preg_match('/^(\d+)\.props\.bg_image$/', $path, $match) === 1 && $this->takesLookPhoto((int) $match[1])) {
            $this->setBlockProp("{$match[1]}.props.bg", 'image');
        }
    }

    public function clearBlockImage(string $path): void
    {
        $this->authorizeEditing();
        $this->setBlockProp($path, null);
    }

    /** Set a dotted prop path inside $blocks (e.g. "0.props.logos.1.image_url"), keeping the list type. */
    private function setBlockProp(string $path, mixed $value): void
    {
        $blocks = $this->blocks;
        data_set($blocks, $path, $value);
        /** @var list<array<string, mixed>> $blocks */
        $this->blocks = $blocks;
    }

    /**
     * The formatted-text blocks of a layout in the editor's form: library pictures by address, so no
     * id reaches the browser (as in the written body).
     *
     * @param  list<array{type: string, props: array<string, mixed>}>  $blocks
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    private function layoutForEditor(array $blocks): array
    {
        $prose = app(EntryProseBody::class);
        foreach ($blocks as $index => $block) {
            if ($block['type'] === EntryProseBody::TYPE && is_string($block['props']['html'] ?? null)) {
                $blocks[$index]['props']['html'] = $prose->forEditor($block['props']['html']);
            }
        }

        return $blocks;
    }

    /**
     * The layout as stored: each formatted-text block names its library pictures by id. A picture
     * that is not one of this site's library pictures refuses: saving would drop it.
     *
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    private function layoutForStorage(): array
    {
        $prose = app(EntryProseBody::class);
        $blocks = $this->blocks;
        $foreign = 0;
        foreach ($blocks as $index => $block) {
            $props = $block['props'] ?? null;
            if (($block['type'] ?? null) === EntryProseBody::TYPE && is_array($props) && is_string($props['html'] ?? null)) {
                $stored = $prose->forStorage($props['html']);
                $props['html'] = $stored['html'];
                $blocks[$index]['props'] = $props;
                $foreign += $stored['foreign'];
            }
        }
        if ($foreign > 0) {
            throw ValidationException::withMessages(['blocks' => __('entries.ed.body_foreign_images', ['n' => $foreign])]);
        }

        /** @var list<array{type: string, props: array<string, mixed>}> $blocks */
        return $blocks;
    }

    /**
     * The name, chip, glyph and tint of every kind «Trình dựng» shows: the body's blocks and the
     * kinds the add list offers, in the add list's order.
     *
     * @return array<string, array{label: string, chip: string, icon: string, tint: string}>
     */
    private function blockKinds(): array
    {
        $kinds = [];
        foreach ([...app(ComponentRegistry::class)->listedTypes(), ...array_column($this->blocks, 'type')] as $type) {
            if (is_string($type)) {
                $kinds[$type] ??= BlockKinds::of($type);
            }
        }

        return $kinds;
    }

    /**
     * What each block's "Trình bày" panel shows: the colour set it paints today, whether a heavy set
     * (brand, dark) repeats on a neighbour (two such bands side by side read as one), the photo band,
     * the first block (it never moves) and whether its spacing is shown apart.
     *
     * @return array<int, array{scheme: string, clash: bool, image: bool, first: bool, split: bool}>
     */
    private function looks(): array
    {
        $schemes = [];
        $types = [];
        foreach (array_keys($this->blocks) as $i) {
            $props = $this->lookProps($i);
            $types[$i] = is_string($this->blocks[$i]['type'] ?? null) ? $this->blocks[$i]['type'] : '';
            $schemes[$i] = ($props['bg'] ?? null) === 'image' ? null : StyleProps::schemeOf($props, $types[$i]);
        }

        $looks = [];
        foreach (array_keys($this->blocks) as $i) {
            $scheme = $schemes[$i];
            $heavy = $scheme === 'brand' || $scheme === 'dark';
            $looks[$i] = [
                'scheme' => $scheme ?? StyleProps::schemeOf(['bg' => 'scheme'] + $this->lookProps($i), $types[$i]),
                'clash' => $heavy && (($schemes[$i - 1] ?? null) === $scheme || ($schemes[$i + 1] ?? null) === $scheme),
                'image' => $scheme === null,
                'first' => $i === 0,
                'split' => $this->spacingSplit($i),
            ];
        }

        return $looks;
    }

    /**
     * The site's colours for each set, so a "Bộ màu" card shows the band it paints: `light` is the
     * page itself, the others the roles ThemeResolver derives.
     *
     * @return array<string, array{bg: string, head: string, body: string, button: string, button_text: string}>
     */
    private function schemeColours(): array
    {
        $variables = app(ThemeResolver::class)->cssVariables();
        $colours = ['light' => [
            'bg' => $variables['surface'], 'head' => $variables['ink'], 'body' => $variables['body'],
            'button' => $variables['primary'], 'button_text' => $variables['color-on-primary'],
        ]];
        foreach (['soft', 'brand', 'dark'] as $set) {
            $colours[$set] = [
                'bg' => $variables["sch-{$set}-bg"], 'head' => $variables["sch-{$set}-head"], 'body' => $variables["sch-{$set}-body"],
                'button' => $variables["sch-{$set}-pri-bg"], 'button_text' => $variables["sch-{$set}-pri-fg"],
            ];
        }

        return $colours;
    }

    /**
     * The hand-picked product keys of the product grids in the body that name no published product of
     * this site (deleted, unpublished or another site's), upper case: the list shows them as gone. One query.
     *
     * @return list<string>
     */
    private function goneProductKeys(): array
    {
        $keys = [];
        foreach ($this->blocks as $block) {
            $props = Cast::arr($block['props'] ?? null);
            if (($block['type'] ?? null) === 'product_grid' && ($props['source'] ?? null) === 'picked') {
                $keys = array_merge($keys, Cast::arr($props['product_keys'] ?? null));
            }
        }

        return ProductGridComponent::goneKeys($keys);
    }

    /**
     * Preview URLs for every block image prop, resolved in ONE query (a thumbnail sits next to each
     * picker). A media_id → its URL; unknown ids are simply absent.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $schemas
     * @return array<int, string>
     */
    private function resolveBlockImageUrls(array $schemas): array
    {
        $ids = [];
        foreach ($this->blocks as $block) {
            $type = $block['type'] ?? null;
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            if (is_string($type) && isset($schemas[$type])) {
                $ids = array_merge($ids, SchemaImages::ids($props, $schemas[$type]));
            }
        }

        return app(MediaLibraryUrls::class)->urlsForIds($ids);
    }

    /**
     * Resolve a list-typed prop: its current items (re-indexed) + a blank item template
     * shaped by the schema `of`. Returns null when #index.$key isn't an authorable list.
     *
     * @return array{items: array<int, mixed>, blank: mixed}|null
     */
    private function resolveList(int $index, string $key): ?array
    {
        if (! array_key_exists($index, $this->blocks)) {
            return null;
        }

        $type = $this->blocks[$index]['type'] ?? null;
        $registry = app(ComponentRegistry::class);
        if (! is_string($type) || ! $registry->has($type)) {
            return null;
        }

        $spec = $registry->schemaFor($type)[$key] ?? null;
        if (! is_array($spec) || ($spec['type'] ?? null) !== 'list') {
            return null;
        }

        $props = $this->blocks[$index]['props'] ?? null;
        $current = is_array($props) ? ($props[$key] ?? null) : null;

        return [
            'items' => is_array($current) ? array_values($current) : [],
            'blank' => $this->blankFromOf($spec['of'] ?? null),
        ];
    }

    /** A blank item value for a list's `of` schema (a scalar default, or a field map). */
    private function blankFromOf(mixed $of): mixed
    {
        if (! is_array($of)) {
            return '';
        }

        return isset($of['type']) && is_string($of['type'])
            ? $this->defaultForSpec($of)
            : $this->defaultsFromSchema($of);
    }

    /**
     * Write items back into a block's list prop (copy-narrow-reassign for static safety).
     *
     * @param  array<int, mixed>  $items
     */
    private function putItems(int $index, string $key, array $items): void
    {
        $blocks = $this->blocks;
        $block = $blocks[$index];
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $props[$key] = $items;
        $block['props'] = $props;
        $blocks[$index] = $block;
        $this->blocks = $blocks;
    }

    private function swapItems(int $index, string $key, int $a, int $b): void
    {
        $list = $this->resolveList($index, $key);
        if ($list === null || ! array_key_exists($a, $list['items']) || ! array_key_exists($b, $list['items'])) {
            return;
        }

        $items = $list['items'];
        [$items[$a], $items[$b]] = [$items[$b], $items[$a]];
        $this->putItems($index, $key, $items);
    }

    /**
     * Starter props for a freshly added block, derived from its §E7 schema.
     *
     * @return array<string, mixed>
     */
    private function defaultProps(string $type): array
    {
        $registry = app(ComponentRegistry::class);

        return $registry->has($type) ? $this->defaultsFromSchema($registry->get($type)->schema()) : [];
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return array<string, mixed>
     */
    private function defaultsFromSchema(array $schema): array
    {
        $props = [];
        foreach ($schema as $prop => $spec) {
            $props[(string) $prop] = $this->defaultForSpec($spec);
        }

        return $props;
    }

    /** A prop's starter value from its spec (array with a `type`); safe default otherwise. */
    private function defaultForSpec(mixed $spec): mixed
    {
        $type = is_array($spec) ? ($spec['type'] ?? 'text') : 'text';
        $default = is_array($spec) ? ($spec['default'] ?? null) : null;

        return match ($type) {
            'list' => [],
            'int' => $default ?? 0,
            'image', 'video' => $default,   // a media_id, or null for an unset picker
            default => $default ?? '',
        };
    }

    /**
     * Every registered type's §E7 schema with the look props, for the editor view to render
     * inputs/repeaters (the look props, group `style`, render on the "Trình bày" panel instead).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function schemas(): array
    {
        $registry = app(ComponentRegistry::class);
        $schemas = [];
        foreach ($registry->types() as $type) {
            $schemas[$type] = $registry->schemaFor($type);
        }

        return $schemas;
    }

    /**
     * A saved number outside its schema's range (a list block's `limit` is 1-50) is pulled into it
     * when the block loads, so saving it again never fails on a value the visitor-facing page already
     * treated as the nearest one. A value that is not a number at all reads as the schema default.
     *
     * @param  array<array-key, mixed>  $props
     * @return array<array-key, mixed>
     */
    private function clampNumbers(string $type, array $props): array
    {
        $registry = app(ComponentRegistry::class);
        if (! $registry->has($type)) {
            return $props;
        }

        foreach ($registry->get($type)->schema() as $prop => $spec) {
            if (($spec['type'] ?? null) !== 'int' || ! array_key_exists($prop, $props)
                || (! isset($spec['min']) && ! isset($spec['max'])) || $props[$prop] === null || $props[$prop] === '') {
                continue;
            }
            $value = $props[$prop];
            $props[$prop] = is_numeric($value)
                ? min($spec['max'] ?? PHP_INT_MAX, max($spec['min'] ?? PHP_INT_MIN, (int) $value))
                : ($spec['default'] ?? $spec['min'] ?? 0);
        }

        return $props;
    }

    /**
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    private function normalizeBlocks(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $normalized = [];
        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['type']) && is_string($block['type'])) {
                /** @var array<string, mixed> $props block props are keyed by prop name (a stored body is JSON objects) */
                $props = $this->clampNumbers($block['type'], is_array($block['props'] ?? null) ? $block['props'] : []);
                $normalized[] = ['type' => $block['type'], 'props' => $props];
            }
        }

        return $normalized;
    }

    /**
     * A typed link anchor becomes a valid one ("Liên hệ" → "lien-he"); blank clears it. One that another
     * block or the page layout already uses gets a number ("lien-he-2"), so a link never lands on the
     * wrong place. Text with no Latin letter or digit (お問い合わせ, emoji) stays as typed, with a message
     * saying how to fix it, instead of vanishing. Other keys are left alone.
     */
    private function settleAnchor(mixed $value, ?string $key): void
    {
        if ($key === null || preg_match('/^(\d+)\.props\.anchor_id$/', $key, $match) !== 1) {
            return;
        }

        $index = (int) $match[1];
        $field = "blocks.{$index}.props.anchor_id";
        $typed = is_string($value) ? trim($value) : '';
        $anchor = rtrim(Str::limit(Str::slug($typed), 64, ''), '-');
        if ($typed !== '' && $anchor === '') {
            $this->addError($field, __('entries.look_anchor_invalid'));

            return;
        }

        $this->resetErrorBag($field);
        $props = $this->lookProps($index);
        if ($anchor === '') {
            unset($props['anchor_id']);
        } else {
            $props['anchor_id'] = $this->freeAnchor($anchor, $index);
        }
        $this->setBlockProp("{$index}.props", $props);
    }

    abstract private function authorizeEditing(): void;
}
