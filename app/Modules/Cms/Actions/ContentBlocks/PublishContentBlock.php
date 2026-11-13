<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\ContentBlocks;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Publish a block, so the website shows it wherever it is placed, or ($publish = false) take it back
 * to draft, so the website shows nothing there again. Places that name it keep naming it either way.
 * Doing what is already the case changes nothing and records nothing. Asks ContentBlockPolicy first;
 * runs inside the tenant context.
 */
final readonly class PublishContentBlock
{
    /**
     * @throws AuthorizationException
     * @throws ContentBlockChangeRejectedException
     */
    public function handle(User $actor, ContentBlock $block, bool $publish = true): ContentBlock
    {
        Gate::forUser($actor)->authorize('publish', $block);

        return DB::transaction(function () use ($block, $publish): ContentBlock {
            $fresh = ContentBlockRules::reload($block);
            $status = $publish ? ContentBlock::PUBLISHED : ContentBlock::DRAFT;
            if ($fresh->status === $status) {
                return $fresh;
            }

            $fresh->forceFill(['status' => $status])->save();

            Audit::record($publish ? 'cms.content_block.published' : 'cms.content_block.unpublished', [
                'auditable_type' => ContentBlock::class,
                'auditable_id' => $fresh->id,
                'meta' => ['key' => $fresh->key],
            ]);

            return $fresh;
        });
    }
}
