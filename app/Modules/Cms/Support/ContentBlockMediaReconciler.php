<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\ContentBlock;

/**
 * Materializes the pictures and files a shared block's body uses (its content in every language)
 * into the library's "in use" rows (mediables, collection content, owner `content_block`), through
 * the shared BlockMediaUsage every owner of blocks uses. So a picture shown only through a block is
 * never listed as unused, nor offered for deletion from under the places that show the block.
 *
 * Called by every write of a block's body (the save, a copy) inside its transaction, and by
 * media:reconcile-content-usage. The rows go with the block when it is deleted (ContentBlockObserver):
 * the attachment is polymorphic, no foreign key cascades it (D3). Holds no state (Octane-safe).
 */
final readonly class ContentBlockMediaReconciler
{
    /** The morph alias the rows are recorded under (CmsServiceProvider). */
    public const string OWNER = 'content_block';

    public function __construct(private BlockMediaUsage $usage) {}

    public function reconcile(ContentBlock $block): void
    {
        $lists = [];
        foreach ($block->translations()->get(['body']) as $translation) {
            $lists[] = is_array($translation->body['blocks'] ?? null) ? $translation->body['blocks'] : [];
        }

        $this->usage->sync(self::OWNER, $block->id, $lists);
    }
}
