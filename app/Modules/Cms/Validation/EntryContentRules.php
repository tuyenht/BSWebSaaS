<?php

declare(strict_types=1);

namespace App\Modules\Cms\Validation;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Theme\Components\BlockReference;
use Closure;

/**
 * The rules one locale's content must pass to be written: the editor's save and a revision
 * restore read them from here, so nothing reaches entry_translations by one road that the other
 * would refuse. `blocks` is the body's block list, checked against the component registry
 * (ValidPageJson; a shared block the entry already stores stays allowed once it is gone,
 * storedRefs). The excerpt and the Google title and description are optional and fit their
 * varchar(255) columns (`max` counts characters, not bytes); a form whose fields carry other names
 * maps them (Entries\Editor).
 */
final readonly class EntryContentRules
{
    public const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Who a post is by, as readers see it (entry_translations.author_name, varchar(80)): one line of
     * plain text naming a team or department, never a staff account.
     */
    public const int AUTHOR_NAME_MAX = 80;

    /**
     * The byline to store: one line of plain text (PlainText::label), null when empty. False when it
     * cannot be stored: longer than AUTHOR_NAME_MAX or holding markup.
     */
    public static function authorName(string $text): string|false|null
    {
        $text = PlainText::label($text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::AUTHOR_NAME_MAX || PlainText::hasMarkup($text) ? false : $text;
    }

    /**
     * The words that describe a post's or page's featured image in one language
     * (entry_translations.cover_alt, varchar(250)): what Google and a screen reader take from it.
     */
    public const int COVER_ALT_MAX = 250;

    /**
     * The featured image's words to store: one line of plain text (PlainText::label), null when
     * empty. False when they cannot be stored: longer than COVER_ALT_MAX or holding markup.
     */
    public static function coverAlt(string $text): string|false|null
    {
        $text = PlainText::label($text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::COVER_ALT_MAX || PlainText::hasMarkup($text) ? false : $text;
    }

    /**
     * «Từ khóa muốn lên top»: the words one language of a post or page should be found by
     * (entry_translations.focus_keyword, varchar(120)). The editor's own note: never shown on the website.
     */
    public const int FOCUS_KEYWORD_MAX = 120;

    /**
     * The focus keyword to store: one line of plain text (PlainText::label), null when empty. False
     * when it cannot be stored: longer than FOCUS_KEYWORD_MAX or holding markup.
     */
    public static function focusKeyword(string $text): string|false|null
    {
        $text = PlainText::label($text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::FOCUS_KEYWORD_MAX || PlainText::hasMarkup($text) ? false : $text;
    }

    public function __construct(private ValidPageJson $blocks) {}

    /**
     * The shared blocks an entry stores now (ValidPageJson::keeping): those its live bodies place, in
     * every language, and those its open pending change places (entry_drafts.content, per language),
     * so approving, saving or folding a change that places a block deleted since is not refused.
     * Read in two site-scoped queries when called: only when a placed block is not found.
     *
     * @return Closure(): list<string>
     */
    public static function storedRefs(string $entryUlid): Closure
    {
        return static function () use ($entryUlid): array {
            $entryId = Entry::withTrashed()->select('id')->where('ulid', $entryUlid);
            $lists = [];
            $bodies = EntryTranslation::query()->whereIn('entry_id', $entryId)->get(['id', 'site_id', 'entry_id', 'body']);
            foreach ($bodies as $translation) {
                $lists[] = Cast::arr($translation->body['blocks'] ?? null);
            }
            $change = EntryDraft::query()->whereIn('entry_id', $entryId)->first(['id', 'site_id', 'entry_id', 'content']);
            foreach (Cast::arr($change?->content['locales'] ?? null) as $text) {
                $body = is_array($text) ? ($text['body'] ?? null) : null;
                $lists[] = Cast::arr(is_array($body) ? ($body['blocks'] ?? null) : null);
            }

            return BlockReference::ulidsIn($lists);
        };
    }

    /**
     * @param  list<string>|Closure(): list<string>  $keptRefs  the shared blocks the entry stores now (storedRefs)
     * @return array{title: list<string>, slug: list<string>, blocks: list<ValidPageJson>, excerpt: list<string>, seo_title: list<string>, seo_description: list<string>}
     */
    public function rules(array|Closure $keptRefs = []): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'regex:'.self::SLUG_PATTERN],
            'blocks' => [$this->blocks->keeping($keptRefs)],
            'excerpt' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Why an entry's `meta` cannot be stored (the short facts and the badge of a card, JobFacts; the
     * day a post shows as published, DisplayDate); empty when it is fine. The editor writes only the
     * display day; a revision restore, a pending change and a content pack carry them all.
     *
     * @param  array<array-key, mixed>  $meta
     * @return list<string>
     */
    public function metaErrors(array $meta): array
    {
        return [...JobFacts::errors($meta), ...DisplayDate::errors($meta)];
    }

    /**
     * Plain-language messages for the rules above (the framework's own are English only); `:max`
     * is filled in by the validator from the rule, so the limit is stated once.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.max' => __('entries.title_too_long'),
            'slug.max' => __('entries.slug_too_long'),
            'slug.regex' => __('entries.slug_format'),
            'excerpt.max' => __('entries.excerpt_too_long'),
            'seo_title.max' => __('entries.seo_title_too_long'),
            'seo_description.max' => __('entries.seo_description_too_long'),
        ];
    }
}
