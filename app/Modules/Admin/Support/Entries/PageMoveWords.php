<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Exceptions\PageMoveRejectedException;
use App\Modules\Shared\Support\Cast;

/**
 * Why the page tree refused a change, in the design's words (board 05 «Quản lý bài viết», checkMove):
 * one wording for the Trang list's moves and the editor's «Trang cha».
 */
final class PageMoveWords
{
    /** The refusal's sentence. */
    public static function why(PageMoveRejectedException $refused): string
    {
        return match ($refused->reason) {
            'too_deep' => self::depth($refused->height),
            'path_taken' => Cast::string(__('entries.move_path_taken', ['path' => $refused->path])),
            default => Cast::string(__('entries.move_refused_'.$refused->reason)),
        };
    }

    /** The three-level rule, said for a branch of $height levels (1: a page without sub-pages). */
    public static function depth(int $height): string
    {
        return Cast::string(match (true) {
            $height <= 1 => __('entries.move_depth_page'),
            $height === 2 => __('entries.move_depth_branch_l1', ['n' => $height]),
            default => __('entries.move_depth_branch_top', ['n' => $height]),
        });
    }
}
