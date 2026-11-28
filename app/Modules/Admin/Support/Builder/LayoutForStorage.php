<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Builder;

use App\Modules\Admin\Support\EntryProseBody;
use Illuminate\Validation\ValidationException;

/**
 * The editor's block list as it is stored: each formatted-text block names its library pictures by
 * id (EntryProseBody::forStorage). A picture that is not one of this site's library pictures refuses:
 * saving would drop it. One place for every path that turns the editor's form into a body — saving
 * (EditsBlockList) and the page builder's preview frame (BuilderPreviewController) — so the frame
 * shows exactly what a save would store. Holds no state.
 */
final readonly class LayoutForStorage
{
    /**
     * @param  array<array-key, mixed>  $blocks  the editor's form of the blocks
     * @return list<array{type: string, props: array<string, mixed>}>
     *
     * @throws ValidationException when a formatted-text block shows a picture of no library of this site
     */
    public function blocks(array $blocks): array
    {
        $prose = app(EntryProseBody::class);
        $foreign = 0;
        foreach ($blocks as $index => $block) {
            $props = is_array($block) ? ($block['props'] ?? null) : null;
            if (is_array($block) && ($block['type'] ?? null) === EntryProseBody::TYPE && is_array($props) && is_string($props['html'] ?? null)) {
                $stored = $prose->forStorage($props['html']);
                $props['html'] = $stored['html'];
                $block['props'] = $props;
                $blocks[$index] = $block;
                $foreign += $stored['foreign'];
            }
        }
        if ($foreign > 0) {
            throw ValidationException::withMessages(['blocks' => __('entries.ed.body_foreign_images', ['n' => $foreign])]);
        }

        /** @var list<array{type: string, props: array<string, mixed>}> $blocks */
        return $blocks;
    }
}
