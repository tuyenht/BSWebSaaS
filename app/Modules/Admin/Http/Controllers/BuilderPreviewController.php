<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\Builder\BuilderFrameError;
use App\Modules\Admin\Support\Builder\LayoutForStorage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Shared\Support\BuilderPreview;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Storefront\Rendering\EntryPreviewPage;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page builder's preview frame (admin.entries.builder-preview): the real storefront page of an
 * entry of this site drawn from the editor's UNSAVED blocks, posted by the builder as a
 * multipart form into a hidden frame. Writes nothing (no translation, revision, route, view count or
 * cached page): the blocks go through the same steps a save takes (LayoutForStorage, then
 * ValidPageJson) and are drawn from a translation that lives only in memory.
 *
 * In order: the entry by ulid (global scope + RLS), the right to EDIT it (not only to see it) and a
 * language the site has switched on — else 404, the same for each, so a ulid probe learns nothing; a
 * request over MAX_REQUEST_BYTES or blocks longer than the body's ceiling plus the form's margin →
 * 413 before anything is decoded; a nonce that is not 32 lower-case hex digits → 422 (a string the
 * client sent is never printed unchecked); blocks a save would refuse → 422. A language the site has
 * on that the entry has no translation in yet is drawn from the posted title and blocks (the editor
 * opens it as an empty form), never 404; a title there over the save's length → 422. Every refusal
 * is BuilderFrameError's small page, so the frame's script can tell the editor; what the middleware
 * raise (403, 404, 413, 419, 429, 503) and a fault of ours (500) get it from AdminErrorPages.
 *
 * At most PER_MINUTE previews a minute per member and site (LIMITER, AdminServiceProvider).
 */
final readonly class BuilderPreviewController
{
    public const string ROUTE = 'admin.entries.builder-preview';

    public const string LIMITER = 'builder-preview';

    public const int PER_MINUTE = 120;

    /** The largest request accepted (bytes): a full body sent as multipart/form-data fits well under it. */
    public const int MAX_REQUEST_BYTES = 409_600;

    /** What the blocks field may hold beyond the body's ceiling: the editor's form of a body is a little longer than the stored one. */
    public const int FORM_MARGIN_BYTES = 65_536;

    public function __construct(
        private SiteLocale $locales,
        private LayoutForStorage $layout,
        private EntryPreviewPage $pages,
    ) {}

    public function __invoke(Request $request, string $ulid): Response
    {
        $locale = $request->input('locale', $this->locales->default());
        $entry = is_string($locale) && $this->locales->isEnabled($locale)
            ? Entry::query()->where('ulid', $ulid)->addSelect([EntryCover::KEY => EntryCover::keySubquery()])->first()
            : null;
        // the right to edit, decided on the saved entry; not allowed answers like a missing entry
        if (! is_string($locale) || $entry === null || ! Gate::allows('update', $entry)) {
            return BuilderFrameError::response(404, $request);
        }

        $raw = $request->input('blocks');
        if (Cast::int($request->headers->get('Content-Length')) > self::MAX_REQUEST_BYTES
            || (is_string($raw) && strlen($raw) > ValidPageJson::MAX_BYTES + self::FORM_MARGIN_BYTES)) {
            return BuilderFrameError::response(413, $request);
        }
        $nonce = BuilderPreview::nonceOf($request->input('nonce'));
        $blocks = $nonce !== null && is_string($raw) ? $this->stored($raw, $entry) : null;
        if ($nonce === null || $blocks === null) {
            return BuilderFrameError::response(422, $request);
        }

        $translation = $this->translation($entry, $locale, $request->input('title'), $blocks);
        if ($translation === null) {
            return BuilderFrameError::response(422, $request);
        }

        BuilderPreview::enable($request, $nonce);

        return $this->pages->respond($request, $entry, $translation, $locale);
    }

    /** Whether $request is one for this route, matched by its address (a refusal may come before routing: a body over PHP's limit). */
    public static function handles(Request $request): bool
    {
        $prefix = Cast::string(config('admin.prefix', 'admin'));

        return $request->isMethod('POST') && $request->is($prefix.'/content/*/builder-preview');
    }

    /**
     * The posted blocks as a save would store them, or null when a save would refuse them.
     *
     * @return list<array{type: string, props: array<string, mixed>}>|null
     */
    private function stored(string $raw, Entry $entry): ?array
    {
        $decoded = json_decode($raw, true, 64);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }
        // only what a block is (its type and props): nothing else of the client's reaches the page
        $blocks = [];
        foreach ($decoded as $block) {
            if (! is_array($block)) {
                return null;
            }
            $blocks[] = ['type' => $block['type'] ?? null, 'props' => $block['props'] ?? null];
        }
        try {
            $stored = $this->layout->blocks(PlainText::linesIn($blocks));
        } catch (ValidationException) {
            return null;
        }
        // the rule a save checks the body with: a shared block the entry stores now stays allowed once gone
        $rule = app(ValidPageJson::class)->keeping(EntryContentRules::storedRefs($entry->ulid));

        return Validator::make(['blocks' => $stored], ['blocks' => [$rule]])->fails() ? null : $stored;
    }

    /**
     * The translation drawn: the saved one with the posted blocks, or — in a language the entry has
     * none in yet — one made from the posted title and blocks, or null when a save would refuse that
     * title (the editor's rule: one line of plain text, at most 200 characters; empty is drawn). Neither is
     * ever saved.
     *
     * @param  list<array{type: string, props: array<string, mixed>}>  $blocks
     */
    private function translation(Entry $entry, string $locale, mixed $title, array $blocks): ?EntryTranslation
    {
        $translation = $entry->translationFor($locale);
        if ($translation === null) {
            // an empty field reaches here as null (ConvertEmptyStringsToNull)
            $title ??= '';
            if (! is_string($title)) {
                return null;
            }
            // normalized first, then the rule a save checks, as the editor does (Editor::rules), less
            // `required`: the editor opens a new language with an empty title, and the frame still draws it
            $title = PlainText::line($title);
            $rule = array_values(array_filter(app(EntryContentRules::class)->rules()['title'], fn (mixed $r): bool => $r !== 'required'));
            if (Validator::make(['title' => $title], ['title' => $rule])->fails()) {
                return null;
            }
            $translation = new EntryTranslation([
                'locale' => $locale,
                'title' => $title,
                'slug' => Str::slug($title) !== '' ? Str::slug($title) : Str::lower($entry->ulid),
                'status' => 'draft',
            ]);
            $translation->entry_id = $entry->id;
        }
        $translation->body = ['blocks' => $blocks];

        return $translation;
    }
}
