<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A category item's `layout_blocks` (taxonomies.json): the block slots the pack names are written
 * to the category's meta next to its other keys; a slot the pack does not name keeps what staff set.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/** @return array<string, array<string, array{blocks: list<array<string, mixed>>}>> one slot with one heading block */
function tlbSlot(string $slot = 'after_list', string $text = 'Gửi yêu cầu'): array
{
    return [$slot => ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['variant' => 'card', 'heading' => $text, 'button_label' => 'Liên hệ', 'button_url' => '/lien-he']]]]]];
}

function tlbPack(string $folder, mixed $layout): void
{
    ContentPacks::item($folder, 'taxonomies.json', 'tax:post_cat/tin-tuc', fn (array $item): array => ['layout_blocks' => $layout] + $item);
}

function tlbProblems(): string
{
    return implode("\n", app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co'));
}

/** @return array<string, mixed> the live meta of the category behind a slug */
function tlbMeta(int $siteId, string $slug): array
{
    return ContentPacks::in($siteId, fn (): array => (array) Taxonomy::query()->findOrFail(TaxonomyTranslation::query()->where('slug', $slug)->value('taxonomy_id'))->meta);
}

it('accepts a category with block slots', function (): void {
    tlbPack($this->folder, tlbSlot());

    expect(tlbProblems())->toBe('');
});

it('refuses layout_blocks that are not a map of known slots', function (mixed $layout, string $problem): void {
    tlbPack($this->folder, $layout);

    expect(tlbProblems())->toContain($problem);
})->with([
    'a list' => [[['type' => 'cta']], 'layout_blocks must be'],
    'an empty map' => [[], 'layout_blocks must be'],
    'an unknown slot' => [['nowhere' => ['vi' => ['blocks' => []]]], "'nowhere' is not a block slot"],
]);

it('refuses layout_blocks on a vocabulary that has no block slots', function (): void {
    file_put_contents($this->folder.'/taxonomies.json', json_encode(['items' => [
        ['key' => 'tax:tag/nhan', 'name' => ['vi' => 'Nhãn'], 'layout_blocks' => tlbSlot()],
    ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    expect(tlbProblems())->toContain('layout_blocks belong to a post_cat or product_cat category');
});

it('writes the slots, keeps the other meta keys and then plans the item unchanged', function (): void {
    tlbPack($this->folder, tlbSlot());

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $meta = tlbMeta($this->siteId, 'tin-tuc');
    expect($meta['layout_blocks']['after_list']['vi']['blocks'][0]['props']['heading'])->toBe('Gửi yêu cầu');

    [, $again] = ContentPacks::run();
    expect(ContentPacks::verdict($again, 'tax:post_cat/tin-tuc'))->toBe('unchanged');
});

it('keeps other meta keys of the category', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, function (): void {
        Taxonomy::query()->whereKey(TaxonomyTranslation::query()->where('slug', 'tin-tuc')->value('taxonomy_id'))->update(['meta->keep_me' => 'yes']);
    });
    tlbPack($this->folder, tlbSlot());
    ContentPacks::load('demo-co', ['tax:post_cat/tin-tuc']);

    expect(tlbMeta($this->siteId, 'tin-tuc'))->toHaveKey('keep_me', 'yes')->toHaveKey('layout_blocks');
});

it('skips a category staff edited until it is forced, and a slot the pack does not name survives the force', function (): void {
    tlbPack($this->folder, tlbSlot('after_list', 'Bản của gói'));
    ContentPacks::load();
    ContentPacks::in($this->siteId, function (): void {
        $id = TaxonomyTranslation::query()->where('slug', 'tin-tuc')->value('taxonomy_id');
        $meta = (array) Taxonomy::query()->findOrFail($id)->meta;
        $meta['layout_blocks']['after_list']['vi']['blocks'][0]['props']['heading'] = 'Bản của nhân viên';
        $meta['layout_blocks']['post_aside'] = ['vi' => ['blocks' => []]];
        Taxonomy::query()->whereKey($id)->update(['meta' => $meta]);
    });
    tlbPack($this->folder, tlbSlot('after_list', 'Bản mới của gói'));

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'tax:post_cat/tin-tuc'))->toBe('skip:edited');
    ContentPacks::load();
    expect(tlbMeta($this->siteId, 'tin-tuc')['layout_blocks']['after_list']['vi']['blocks'][0]['props']['heading'])->toBe('Bản của nhân viên');

    [$exit, $forced] = ContentPacks::load('demo-co', ['tax:post_cat/tin-tuc']);
    expect($exit)->toBe(0, $forced);
    $layout = tlbMeta($this->siteId, 'tin-tuc')['layout_blocks'];
    expect($layout['after_list']['vi']['blocks'][0]['props']['heading'])->toBe('Bản mới của gói')
        ->and($layout)->toHaveKey('post_aside');
});
