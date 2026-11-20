<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Admin\Support\BlockKinds;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Shared\Support\Cast;
use App\Modules\Theme\Components\ComponentRegistry;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Str;

/**
 * A stored body as lines of text, for a screen that shows what a body says rather than the body
 * itself: a version in the history, a pending change beside what visitors see. A written body reads
 * paragraph by paragraph (each list item its own line), a laid-out body block by block, named by its
 * kind. Stateless (Octane).
 */
final readonly class EntryBodyLines
{
    /**
     * @param  list<array{type: string, props: array<string, mixed>}>  $blocks
     * @return list<array{k: string, t: string}>
     */
    public function lines(array $blocks): array
    {
        $prose = app(EntryProseBody::class)->read($blocks);
        if ($prose !== null) {
            return $this->htmlLines($prose['html']);
        }

        $lines = [];
        foreach ($blocks as $block) {
            $words = '';
            foreach ($block['props'] as $value) {
                if (is_string($value) && ($text = trim(html_entity_decode(strip_tags($value)))) !== '' && ! str_starts_with($text, 'http')) {
                    $words = Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, 160);
                    break;
                }
            }
            $label = Cast::string(BlockKinds::of($block['type'])['label']);
            $lines[] = ['k' => 'b', 't' => $words !== '' ? $label.': '.$words : $label];
        }

        return $lines;
    }

    /**
     * @return list<array{k: string, t: string}>
     */
    public function htmlLines(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $body = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR)->body;
        if ($body === null) {
            return [];
        }

        $lines = [];
        $text = static fn (Element $el): string => trim(preg_replace('/\s+/u', ' ', $el->textContent ?? '') ?? '');
        foreach ($body->children as $el) {
            $tag = strtolower($el->localName);
            if ($tag === 'ul' || $tag === 'ol') {
                foreach ($el->children as $item) {
                    if (($t = $text($item)) !== '') {
                        $lines[] = ['k' => 'li', 't' => $t];
                    }
                }

                continue;
            }
            $t = $text($el);
            if ($t !== '') {
                $lines[] = ['k' => preg_match('/^h[1-6]$/', $tag) === 1 ? 'h' : 'p', 't' => $t];
            } elseif (($img = $tag === 'img' ? $el : $el->querySelector('img')) !== null) {
                $lines[] = ['k' => 'p', 't' => Cast::string(__('entries.rev.image_line', ['alt' => $img->getAttribute('alt') ?? '']))];
            }
        }
        if ($lines === [] && ($t = trim($body->textContent ?? '')) !== '') {
            $lines[] = ['k' => 'p', 't' => $t];
        }

        return $lines;
    }

    /**
     * A stored body (as JSON gives it back) as one line of plain text: only its well-formed blocks,
     * each bounded number setting read as the number the editor loads (so "3" is never taken for text).
     */
    public function plain(mixed $blocks): string
    {
        $wellFormed = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (is_array($block) && is_string($block['type'] ?? null)) {
                /** @var array<string, mixed> $props */
                $props = is_array($block['props'] ?? null) ? $block['props'] : [];
                $wellFormed[] = ['type' => $block['type'], 'props' => $this->numbers($block['type'], $props)];
            }
        }

        return implode(' ', array_column($this->lines($wellFormed), 't'));
    }

    /**
     * The editor's reading of a block's bounded number settings: a number pulled into its range, any
     * other value the schema default.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function numbers(string $type, array $props): array
    {
        $registry = app(ComponentRegistry::class);
        if (! $registry->has($type)) {
            return $props;
        }
        foreach ($registry->get($type)->schema() as $prop => $spec) {
            if (($spec['type'] ?? null) !== 'int' || ! array_key_exists($prop, $props)
                || (! isset($spec['min']) && ! isset($spec['max'])) || $props[$prop] === null || $props[$prop] === '') {
                continue;
            }
            $value = $props[$prop];
            $props[$prop] = is_numeric($value)
                ? min($spec['max'] ?? PHP_INT_MAX, max($spec['min'] ?? PHP_INT_MIN, (int) $value))
                : ($spec['default'] ?? $spec['min'] ?? 0);
        }

        return $props;
    }
}
