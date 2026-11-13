<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\ContentBlocks;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Queries\ContentBlockUsages;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Vẫn xóa": delete a block for good, with its content in every language and its usage rows (the
 * foreign keys cascade). There is no Trash for blocks: the screen asks first and says how many places
 * use it. The places keep the block's ulid, and show nothing on the website from then on. The audit
 * row records how many places used it. Returns that number. Asks ContentBlockPolicy first; runs
 * inside the tenant context.
 */
final readonly class DeleteContentBlock
{
    public function __construct(private ContentBlockUsages $usages) {}

    /**
     * @throws AuthorizationException
     * @throws ContentBlockChangeRejectedException
     */
    public function handle(User $actor, ContentBlock $block): int
    {
        Gate::forUser($actor)->authorize('delete', $block);

        return DB::transaction(function () use ($block): int {
            $fresh = ContentBlockRules::reload($block);
            $places = $this->usages->countsFor([$fresh->id])[$fresh->id] ?? 0;

            $fresh->delete();

            Audit::record('cms.content_block.deleted', [
                'auditable_type' => ContentBlock::class,
                'auditable_id' => $fresh->id,
                'meta' => ['key' => $fresh->key, 'name' => $fresh->name, 'places' => $places],
            ]);

            return $places;
        });
    }
}
