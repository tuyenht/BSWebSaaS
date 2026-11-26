<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Spatie\Permission\Models\Permission;

/*
 * Page weight guardrail for the heaviest admin screens. What the browser has to parse on opening a
 * screen, and walk again after every Livewire answer, grows with the HTML and the number of elements;
 * the server's own time does not show it (the posts list answers in a few queries and still sends
 * most of a megabyte). Measured 26/11/2026 on a member holding every website permission:
 * posts list, 20 rows — 817 KB, 2,878 elements; post editor — 290 KB, 1,240 elements.
 * The ceilings sit about a tenth above that: lower them when a screen gets lighter, and treat a
 * failure as a reason to look at what the change added to every row, not to raise the number.
 */

/** @return array{kb: float, elements: int} */
function adminPageWeight(string $html): array
{
    return ['kb' => round(strlen($html) / 1024, 1), 'elements' => (int) preg_match_all('/<[a-zA-Z]/', $html)];
}

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'An Vinh');
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $this->member = User::factory()->create();

    app(Tenancy::class)->enter(1, function (): void {
        $all = Permission::query()->pluck('name')->reject(fn ($name) => str_starts_with((string) $name, 'platform.'))->values()->all();
        grantRole($this->member, 'everything', $all);

        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
        foreach (range(1, 25) as $i) {
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $this->member->id]);
            $post->translations()->create(['locale' => 'vi', 'title' => "Bài {$i}", 'slug' => "bai-{$i}", 'status' => 'draft', 'body' => ['blocks' => []]]);
            $post->assignCategories([$category->id]);
        }
    });
});

it('keeps the posts list of twenty rows within its page weight', function (): void {
    $html = (string) $this->actingAs($this->member)->get('http://a.test/admin/content?section=posts')->assertOk()->getContent();
    $weight = adminPageWeight($html);

    expect(substr_count($html, 'wire:key="entry-'))->toBe(20)
        ->and($weight['kb'])->toBeLessThanOrEqual(900.0)
        ->and($weight['elements'])->toBeLessThanOrEqual(3170);
});

it('keeps the post editor within its page weight', function (): void {
    $ulid = app(Tenancy::class)->enter(1, fn () => Entry::query()->where('type', 'post')->value('ulid'));
    $html = (string) $this->actingAs($this->member)->get("http://a.test/admin/content/{$ulid}/edit")->assertOk()->getContent();
    $weight = adminPageWeight($html);

    expect($weight['kb'])->toBeLessThanOrEqual(320.0)
        ->and($weight['elements'])->toBeLessThanOrEqual(1370);
});
