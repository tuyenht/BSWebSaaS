<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\ContentBlocks;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Sửa" in the block library: a new name or region for a block. The reference code stays as it is,
 * so every place that names the block keeps working. $version is the token the screen read
 * (ContentBlockVersion); a change made from an older state is refused (version_stale). Returns the
 * new token. Asks ContentBlockPolicy first; runs inside the tenant context.
 */
final readonly class UpdateContentBlock
{
    /**
     * @throws AuthorizationException
     * @throws ContentBlockChangeRejectedException
     */
    public function handle(User $actor, ContentBlock $block, string $name, string $region, string $version): string
    {
        Gate::forUser($actor)->authorize('update', $block);

        $name = ContentBlockRules::name($name);
        $region = ContentBlockRules::region($region);

        return DB::transaction(function () use ($block, $name, $region, $version): string {
            $fresh = ContentBlockRules::reload($block);
            ContentBlockRules::ensureVersion($fresh, $version);

            $before = ['name' => $fresh->name, 'region' => $fresh->region];
            $fresh->forceFill(['name' => $name, 'region' => $region])->save();

            if ($before !== ['name' => $name, 'region' => $region]) {
                Audit::record('cms.content_block.updated', [
                    'auditable_type' => ContentBlock::class,
                    'auditable_id' => $fresh->id,
                    'meta' => ['key' => $fresh->key, 'before' => $before, 'after' => ['name' => $name, 'region' => $region]],
                ]);
            }

            return ContentBlockVersion::current($fresh);
        });
    }
}
