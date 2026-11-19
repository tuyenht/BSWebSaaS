<?php

namespace App\Modules\Admin\Support\Entries;

use Carbon\CarbonImmutable;

/**
 * What the posts and pages list asks for, already cleaned by the screen: the type, the status group,
 * the section's category, the search words and the filter values (OR within a group, AND across).
 */
final readonly class EntryListFilter
{
    /**
     * Tabs that map to one status; "all" is every live row and "trash" every deleted one. The four states
     * a member sets (published, scheduled, draft, archived) and «Chờ duyệt»; the retired `private` has none.
     */
    public const array STATUS_TABS = ['published', 'draft', 'scheduled', 'pending', 'archived'];

    /** The "no template" value of the Mẫu trang filter (a null template_override). */
    public const string TEMPLATE_NONE = '__default__';

    /**
     * @param  'page'|'post'  $type
     * @param  list<string>  $langs  content languages, any of
     * @param  list<string>  $creators  author ulids, any of
     * @param  list<string>  $templates  template names, TEMPLATE_NONE for none
     * @param  list<string>  $trans  translation states (full / partial / none)
     * @param  list<string>  $locales  the site's enabled languages
     * @param  list<int>  $categories  main-category ids, any of (the chosen categories and everything under them)
     * @param  list<int>|null  $within  «Trong phạm vi của tôi»: main-category ids the post must be filed in (the
     *                                  categories the member may write in), or null when that choice is off
     * @param  int|null  $trashOwner  the Trash tab's rows: only this author's (a member who may move only their own
     *                                records to the Trash; 0 for a member with no Trash right: none), or null for all
     */
    public function __construct(
        public string $type,
        public string $tab,
        public ?int $sectionTaxonomyId,
        public string $search,
        public array $langs,
        public array $creators,
        public array $templates,
        public array $trans,
        public array $locales,
        public string $defaultLocale,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $until,
        public array $categories = [],
        public ?array $within = null,
        public ?int $trashOwner = null,
    ) {}

    /** Is any filter value on (the search and the status group aside)? */
    public function filtering(): bool
    {
        return $this->langs !== [] || $this->creators !== [] || $this->templates !== [] || $this->trans !== []
            || $this->from !== null || $this->until !== null || $this->categories !== [] || $this->within !== null;
    }
}
