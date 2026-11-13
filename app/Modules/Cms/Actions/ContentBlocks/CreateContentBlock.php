<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\ContentBlocks;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Thêm khối": a new draft block with a name and the region it is meant for (the list's current
 * filter, else "Chèn trong trang"), and no content yet. Its reference code is made from the name
 * ("-2", "-3"… on a clash) and never changes on rename. Refused once the website keeps
 * `cms.blocks.max_per_site` blocks (library_full). Asks ContentBlockPolicy first; runs inside the
 * tenant context, under the library's per-site lock.
 */
final readonly class CreateContentBlock
{
    public function __construct(private CurrentSite $site) {}

    /**
     * @throws AuthorizationException
     * @throws ContentBlockChangeRejectedException
     */
    public function handle(User $actor, string $name, string $region = ContentBlock::DEFAULT_REGION): ContentBlock
    {
        Gate::forUser($actor)->authorize('create', ContentBlock::class);

        $name = ContentBlockRules::name($name);
        $region = ContentBlockRules::region($region);

        return DB::transaction(function () use ($name, $region): ContentBlock {
            ContentBlockRules::lock($this->site->id());
            ContentBlockRules::ensureRoom();

            $block = ContentBlock::query()->create([
                'key' => ContentBlockRules::freeKey($name),
                'name' => $name,
                'region' => $region,
                'status' => ContentBlock::DRAFT,
            ]);

            Audit::record('cms.content_block.created', [
                'auditable_type' => ContentBlock::class,
                'auditable_id' => $block->id,
                'meta' => ['key' => $block->key, 'name' => $name, 'region' => $region],
            ]);

            return $block->refresh();
        });
    }
}
