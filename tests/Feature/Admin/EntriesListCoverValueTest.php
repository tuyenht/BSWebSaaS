<?php

declare(strict_types=1);

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Livewire\Livewire;

/*
 * An old cover value may be a storage key, a glyph name or an outside address. The list's small
 * picture is drawn only for a picture: a glyph name asked the media host for a file that does not
 * exist, one failed request per row.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('draws the row picture for a stored file or an https address, never for a glyph name', function (): void {
    $user = User::factory()->create();
    app()->setLocale('vi');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Bài viết', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]);
        foreach (['khoa' => 'an-vinh/posts/may-han-0123456789ab.webp', 'hinh' => 'shield', 'ngoai' => 'https://example.com/a.jpg', 'khong' => null] as $slug => $cover) {
            $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $user->id, 'meta' => $cover === null ? [] : ['image_url' => $cover]]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài '.$slug, 'slug' => 'bai-'.$slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
        }

        $html = Livewire::withQueryParams(['section' => 'posts'])->actingAs($user->fresh())->test(Index::class, ['section' => 'posts'])->assertOk()->html();

        preg_match_all('/<span class="list-th" title="[^"]*"><img src="([^"]*)"/', $html, $pictures);
        $outside = array_values(array_filter($pictures[1], fn (string $src): bool => $src === 'https://example.com/a.jpg'));
        $stored = array_values(array_filter($pictures[1], fn (string $src): bool => str_contains($src, 'an-vinh/posts/may-han-0123456789ab.webp')));

        expect($pictures[1])->toHaveCount(2)
            ->and($outside)->toHaveCount(1)
            ->and($stored)->toHaveCount(1)
            ->and($html)->not->toContain('/shield')
            ->and(substr_count($html, 'class="list-th" data-img="0"'))->toBe(2);
    });
});
