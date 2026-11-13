<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\ContentBlocks;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Nhân bản" a block: a new draft named after it (":name (bản sao)"), in the same region, with a copy
 * of every language's content and a new reference code (the old one with "-2", "-3"…). The copy is
 * used nowhere yet. Each body is written through the model, so it is cleaned like any other. Counts
 * against `cms.blocks.max_per_site` (library_full). Returns the copy. Asks ContentBlockPolicy first;
 * runs inside the tenant context, under the library's per-site lock.
 */
final readonly class DuplicateContentBlock
{
    public function __construct(private CurrentSite $site) {}

    /**
     * @throws AuthorizationException
     * @throws ContentBlockChangeRejectedException
     */
    public function handle(User $actor, ContentBlock $block): ContentBlock
    {
        Gate::forUser($actor)->authorize('duplicate', $block);

        return DB::transaction(function () use ($block): ContentBlock {
            ContentBlockRules::lock($this->site->id());
            $source = ContentBlockRules::reload($block);
            ContentBlockRules::ensureRoom();

            $suffix = __('blocks.copy_name', ['name' => '']);
            $room = max(1, ContentBlock::NAME_MAX - mb_strlen($suffix));
            $copy = ContentBlock::query()->create([
                'key' => ContentBlockRules::freeKey($source->key),
                'name' => __('blocks.copy_name', ['name' => mb_substr($source->name, 0, $room)]),
                'region' => $source->region,
                'status' => ContentBlock::DRAFT,
            ]);

            $translations = ContentBlockTranslation::query()
                ->where('content_block_id', $source->id)
                ->orderBy('locale')
                ->get(['locale', 'body']);
            foreach ($translations as $translation) {
                $copy->translations()->create(['locale' => $translation->locale, 'body' => $translation->body]);
            }

            Audit::record('cms.content_block.duplicated', [
                'auditable_type' => ContentBlock::class,
                'auditable_id' => $copy->id,
                'meta' => ['from' => $source->key, 'key' => $copy->key, 'locales' => $translations->pluck('locale')->all()],
            ]);

            return $copy->refresh();
        });
    }
}
