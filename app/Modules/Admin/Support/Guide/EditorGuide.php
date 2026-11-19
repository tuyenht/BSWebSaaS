<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Guide;

use App\Modules\Shared\Support\Cast;

/**
 * The guide of an add or edit screen (a post, a page, a product): the bar every editor shares
 * (components/editor-frame.blade.php) — the way back, the save state, «Xem trước», the save buttons
 * the member has, and the ⋮. Steps point at the frame's data-guide="…" marks; each one has a second
 * mark for the bar along the bottom of a narrow screen, where the save buttons move. Its text is
 * guide.editor.*, the record's noun filled in.
 */
final class EditorGuide
{
    /**
     * @param  string  $screen  the screen's own memory key: editor-post · editor-page · editor-product
     * @param  array{secondary: string|null, primary: string|null}  $actions  the save buttons the bar draws
     * @param  bool  $previewBar  whether «Xem trước» is drawn in the bar (the frame's previewBar is on and the
     *                            button has a link or a reason to say): no step points at a button not drawn
     */
    public static function for(string $screen, string $title, string $unit, bool $isNew, array $actions, bool $previewBar = true): Guide
    {
        $t = fn (string $key): string => Cast::string(__('guide.editor.'.$key, ['unit' => $unit]));

        $guide = new Guide(
            $screen,
            $title,
            ($isNew ? 'new' : 'edit').'|'.($actions['secondary'] !== null ? 's' : '').($actions['primary'] !== null ? 'p' : ''),
            null,
            $t('purpose'),
        );

        return $guide->part('bar', $t('part_bar'), [
            Guide::step('back', [Guide::on('back', $t('back_title'), $t('back'))]),
            Guide::step('state', [Guide::on('state', $t('state_title'), $t('state'))]),
            $previewBar ? Guide::step('preview', [Guide::on('preview', Cast::string(__('admin.editor.preview')), $t($isNew ? 'preview_new' : 'preview'))]) : null,
            $actions['secondary'] === null ? null : Guide::step('save', [
                Guide::on('save', $actions['secondary'], $t('save')),
                Guide::on('save-b', $actions['secondary'], $t('save')),
            ]),
            $actions['primary'] === null ? null : Guide::step('main', [
                Guide::on('main', $actions['primary'], $t('main')),
                Guide::on('main-b', $actions['primary'], $t('main')),
            ]),
            Guide::step('more', [Guide::on('more', Cast::string(__('admin.list.more')), $t('more'))]),
        ]);
    }
}
