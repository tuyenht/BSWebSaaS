<?php

declare(strict_types=1);

namespace App\Modules\Cms\Validation;

use App\Modules\Cms\Support\JobFacts;
use App\Modules\Shared\Support\PlainText;

/**
 * The rules one locale's content must pass to be written: the editor's save and a revision
 * restore read them from here, so nothing reaches entry_translations by one road that the other
 * would refuse. `blocks` is the body's block list, checked against the component registry
 * (ValidPageJson). The excerpt and the Google title and description are optional and fit their
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

    public function __construct(private ValidPageJson $blocks) {}

    /**
     * @return array{title: list<string>, slug: list<string>, blocks: list<ValidPageJson>, excerpt: list<string>, seo_title: list<string>, seo_description: list<string>}
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'regex:'.self::SLUG_PATTERN],
            'blocks' => [$this->blocks],
            'excerpt' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Why an entry's `meta` cannot be stored (the short facts and the badge of a card, JobFacts);
     * empty when it is fine. The editor does not edit these keys, a revision restore and a content
     * pack carry them.
     *
     * @param  array<array-key, mixed>  $meta
     * @return list<string>
     */
    public function metaErrors(array $meta): array
    {
        return JobFacts::errors($meta);
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
