<?php

declare(strict_types=1);

namespace App\Modules\Cms\Observers;

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\ContentBlockMediaReconciler;

/**
 * Drops a deleted block's rows from the library's "in use" index. `mediables` is a polymorphic soft
 * attachment (no foreign key back to content_blocks, D3), so a block deleted through its model would
 * otherwise leave the pictures and files its body named reading as used by a gone block. A delete
 * that skips the model is cleaned by media:reconcile-content-usage.
 */
final readonly class ContentBlockObserver
{
    public function __construct(private BlockMediaUsage $usage) {}

    public function deleted(ContentBlock $block): void
    {
        $this->usage->forget(ContentBlockMediaReconciler::OWNER, [$block->id]);
    }
}
