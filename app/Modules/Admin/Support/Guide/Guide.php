<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Guide;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;

/**
 * One admin screen's guide («Hướng dẫn»): what the shared guide part (x-bs-admin::guide + the lazy
 * resources/js/admin-guide.js) shows for the member reading the screen. A screen builds it from what the
 * server knows of them — their role, the workflow they work under, what they may do and the labels the
 * screen draws — and only with the steps they can use; a step whose element the screen did not draw
 * (or hides at this width) is skipped in the browser.
 *
 * A step points at an element by its data-guide="<target>" attribute and may carry variants: the
 * first one whose element shows is the one read (the status tabs from 900px, the Lọc button below).
 * Every word comes from lang/*.json: the screen's own text under guide.<screen>.*, the engine's under
 * guide.ui.* (printed with the guide, so the script carries no text of its own). In a step's text,
 * [[Label]] is a control's label: the browser quotes it when a control on the screen carries it and
 * writes it plainly otherwise; [[@]] is the label of the element the step points at, and [[@menu]] the
 * items its menu holds when the step shows (a list's «Khác» holds the groups that do not fit).
 */
final class Guide
{
    /** @var list<array{id: string, title: string, steps: list<array<string, mixed>>}> */
    private array $parts = [];

    /** @var list<array{q: string, a: string}> */
    private array $faq = [];

    /**
     * @param  array{name: string, may: string, note: string}|null  $role  the member's role box
     */
    public function __construct(
        private readonly string $screen,
        private readonly string $title,
        private readonly string $scenario,
        private readonly ?array $role,
        private readonly string $purpose,
    ) {}

    /**
     * A part of the guide; steps given as null (not for this member) are left out, and so is a part
     * left without steps.
     *
     * @param  list<array<string, mixed>|null>  $steps
     */
    public function part(string $id, string $title, array $steps): self
    {
        $steps = array_values(array_filter($steps, fn (?array $step): bool => $step !== null));
        if ($steps !== []) {
            $this->parts[] = ['id' => $id, 'title' => $title, 'steps' => $steps];
        }

        return $this;
    }

    /**
     * A step: one or more ways to read it, each on its own element; the first one shown wins.
     *
     * @param  list<array{target: string, title: string, text: string}>  $variants
     * @return array<string, mixed>
     */
    public static function step(string $id, array $variants, bool $intro = false): array
    {
        return ['id' => $id, 'intro' => $intro, 'v' => array_map(
            fn (array $v): array => ['t' => $v['target'], 'title' => $v['title'], 'text' => $v['text']],
            $variants,
        )];
    }

    /**
     * @return array{target: string, title: string, text: string}
     */
    public static function on(string $target, string $title, string $text): array
    {
        return ['target' => $target, 'title' => $title, 'text' => $text];
    }

    /**
     * The screen's common questions, each with its answer (the design's «Hay hỏi ở màn này»), shown
     * in the drawer after the parts; a pair with an empty side is left out.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public function faq(array $pairs): self
    {
        foreach ($pairs as [$q, $a]) {
            if ($q !== '' && $a !== '') {
                $this->faq[] = ['q' => $q, 'a' => $a];
            }
        }

        return $this;
    }

    /** @return list<array{q: string, a: string}> */
    public function questions(): array
    {
        return $this->faq;
    }

    /** @return list<array{id: string, title: string, steps: list<array<string, mixed>>}> */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * What the guide part prints for the browser. The memory key (which parts this member has seen
     * on this screen) is a keyed hash of the website and the member, so two people sharing a
     * computer keep their own, and no id is printed.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $user, int $siteId): array
    {
        $key = Cast::string(config('app.key'));

        return [
            'screen' => $this->screen,
            'title' => $this->title,
            'scenario' => substr(hash_hmac('sha256', $this->scenario, $key), 0, 12),
            'member' => substr(hash_hmac('sha256', $siteId.'|'.Cast::int($user->getAuthIdentifier()), $key), 0, 16),
            'role' => $this->role,
            'purpose' => $this->purpose,
            'parts' => $this->parts,
            'faq' => $this->faq,
            'ui' => self::ui(),
        ];
    }

    /**
     * The engine's own words, in the admin's language.
     *
     * @return array<string, string>
     */
    public static function ui(): array
    {
        $ui = [];
        foreach (['heading', 'close', 'close_tip', 'role', 'part', 'steps', 'steps_one', 'seen', 'show', 'show_named', 'run', 'run_one',
            'exit', 'exit_tip', 'back', 'prev', 'prev_aria', 'next', 'next_aria', 'done', 'next_part', 'count', 'count_intro', 'title_sep', 'quote',
            'offer_title', 'offer_title_changed', 'offer_changed', 'offer_text', 'offer_later', 'offer_now', 'faq', 'list_sep'] as $k) {
            $ui[$k] = Cast::string(__($k === 'list_sep' ? 'guide.list.sep' : 'guide.ui.'.$k));
        }

        return $ui;
    }
}
