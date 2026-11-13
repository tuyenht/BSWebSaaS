<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\ContentBlocks;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Save a block's content in one language (adding that language when it had none): a list of
 * blocks, checked exactly as a page body is (ValidPageJson: registered types only, safe links, this
 * website's pages and files, the block count and size limits), stored as `{"blocks": […]}` through
 * the model so every formatted-text prop is cleaned (SanitizedPageBody). Saving moves the block's
 * "Cập nhật" date. $version is the token the screen read; a save made from an older state is refused
 * (version_stale). Returns the new token. Asks ContentBlockPolicy first; runs inside the tenant
 * context.
 */
final readonly class SaveContentBlockBody
{
    public function __construct(
        private ValidPageJson $pageJson,
        private SiteLocale $locales,
    ) {}

    /**
     * @param  array<array-key, mixed>  $blocks
     *
     * @throws AuthorizationException
     * @throws ContentBlockChangeRejectedException
     */
    public function handle(User $actor, ContentBlock $block, string $locale, array $blocks, string $version): string
    {
        Gate::forUser($actor)->authorize('update', $block);

        if (! in_array($locale, $this->locales->enabled(), true)) {
            throw new ContentBlockChangeRejectedException('locale_invalid');
        }
        if (! array_is_list($blocks)) {
            throw new ContentBlockChangeRejectedException('body_invalid', __('entries.err_blocks_invalid'));
        }
        $validator = Validator::make(['blocks' => $blocks], ['blocks' => [$this->pageJson]]);
        if ($validator->fails()) {
            throw new ContentBlockChangeRejectedException('body_invalid', (string) $validator->errors()->first('blocks'));
        }

        return DB::transaction(function () use ($block, $locale, $blocks, $version): string {
            $fresh = ContentBlockRules::reload($block);
            ContentBlockRules::ensureVersion($fresh, $version);

            $translation = ContentBlockTranslation::query()
                ->where('content_block_id', $fresh->id)
                ->where('locale', $locale)
                ->first() ?? new ContentBlockTranslation(['content_block_id' => $fresh->id, 'locale' => $locale]);
            $translation->body = ['blocks' => $blocks];
            $translation->save();

            Audit::record('cms.content_block.body_saved', [
                'auditable_type' => ContentBlock::class,
                'auditable_id' => $fresh->id,
                'meta' => ['key' => $fresh->key, 'locale' => $locale, 'blocks' => count($blocks)],
            ]);

            return ContentBlockVersion::current($fresh);
        });
    }
}
