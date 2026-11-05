<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Components\StyleProps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * What the page editor shows for each block button ("Khi bấm nút", the page builder design): the
 * kind it does, the value of that kind, the page or file it names, the page's anchors, and the
 * plain-language problem with what was typed. Built once per render for every button on the page,
 * with one query per need (the pages named, the older addresses that are pages, the files named,
 * one search per distinct text typed), never one per button. Site-scoped reads (BelongsToSite + RLS).
 */
final class ButtonActions
{
    /** Search results shown under the page search box. */
    public const int RESULTS = 6;

    /** Icon of each kind (Remix names of the shared icon component). */
    public const array ICONS = [
        'page' => 'ri-file-text', 'url' => 'ri-external-link', 'anchor' => 'ri-arrow-down-circle',
        'tel' => 'ri-phone', 'zalo' => 'ri-chat-3', 'mail' => 'ri-mail', 'file' => 'ri-download-2',
    ];

    /** Icon of a library document by its type. */
    public const array DOC_ICONS = [
        'application/pdf' => 'ri-file-pdf-2',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'ri-file-word-2',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'ri-file-excel-2',
    ];

    /**
     * Every button link on the page: path in $blocks ("0.props.cta_url", "2.props.items.1.url") =>
     * its label text.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<string, array<array-key, mixed>>  $schemas
     * @return array<string, string>
     */
    public static function paths(array $blocks, array $schemas): array
    {
        $paths = [];
        foreach ($blocks as $i => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            $props = is_array($block) && is_array($block['props'] ?? null) ? $block['props'] : [];
            foreach (is_string($type) ? ($schemas[$type] ?? []) : [] as $prop => $spec) {
                $label = ButtonLink::labelProp($spec);
                if ($label !== null) {
                    $paths["{$i}.props.{$prop}"] = Cast::string($props[$label] ?? '');

                    continue;
                }
                $of = is_array($spec) && ($spec['type'] ?? null) === 'list' ? ($spec['of'] ?? null) : null;
                if (! is_array($of) || isset($of['type'])) {
                    continue;
                }
                foreach ($of as $field => $fieldSpec) {
                    $fieldLabel = ButtonLink::labelProp($fieldSpec);
                    if ($fieldLabel === null) {
                        continue;
                    }
                    foreach (is_array($props[$prop] ?? null) ? $props[$prop] : [] as $j => $item) {
                        $paths["{$i}.props.{$prop}.{$j}.{$field}"] = Cast::string(is_array($item) ? ($item[$fieldLabel] ?? '') : '');
                    }
                }
            }
        }

        return $paths;
    }

    /** A path as a key of the panel's state arrays (no dots, so Livewire never reads it as nesting). */
    public static function fid(string $path): string
    {
        return str_replace('.', '-', $path);
    }

    /**
     * The stored value of the button at $path, or the kind an older address sorts into (`auto`).
     *
     * @param  array<array-key, mixed>  $blocks
     * @return array{value: array<string, mixed>, auto: bool, raw: string}
     */
    public static function current(array $blocks, string $path): array
    {
        $do = data_get($blocks, $path.ButtonLink::SUFFIX);
        $raw = data_get($blocks, $path);
        $raw = is_string($raw) ? trim($raw) : '';
        if (is_array($do) && is_string($do['k'] ?? null) && in_array($do['k'], ButtonLink::KINDS, true)) {
            /** @var array<string, mixed> $do */
            return ['value' => $do, 'auto' => false, 'raw' => $raw];
        }

        return ['value' => ButtonLink::sortLegacy($raw), 'auto' => $raw !== '', 'raw' => $raw];
    }

    /**
     * The view of every button.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<string, array<array-key, mixed>>  $schemas
     * @param  array{open: ?string, touched: array<string, bool>, searching: array<string, bool>, queries: array<string, string>}  $state
     * @param  \Closure(): string  $sitePhone  read only when a phone or Zalo button is shown
     * @return array<string, array<string, mixed>>
     */
    public function views(array $blocks, array $schemas, array $state, string $locale, \Closure $sitePhone): array
    {
        $paths = self::paths($blocks, $schemas);
        if ($paths === []) {
            return [];
        }

        $buttons = [];
        $siteText = null;
        $ulids = [];
        $legacyPaths = [];
        $keys = [];
        foreach (array_keys($paths) as $path) {
            $buttons[$path] = self::current($blocks, $path);
            $value = $buttons[$path]['value'];
            $ulid = $value['k'] === 'page' ? Cast::string($value['page'] ?? '') : '';
            $legacy = $value['k'] === 'page' ? Cast::string($value['path'] ?? '') : '';
            $key = $value['k'] === 'file' ? Cast::string($value['file'] ?? '') : '';
            if ($ulid !== '') {
                $ulids[] = $ulid;
            } elseif ($legacy !== '') {
                $legacyPaths[] = $legacy;
            }
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $pages = $this->pages(array_values(array_unique($ulids)), array_values(array_unique($legacyPaths)), $locale);
        $files = $this->files(array_values(array_unique($keys)));
        $anchors = $this->anchors($blocks, $schemas);
        $searches = [];

        $views = [];
        foreach ($paths as $path => $label) {
            $fid = self::fid($path);
            ['value' => $value, 'auto' => $auto, 'raw' => $raw] = $buttons[$path];
            $kind = Cast::string($value['k']);
            $view = [
                'path' => $path, 'fid' => $fid, 'id' => 'btn-act-'.$fid, 'label' => $label,
                'kind' => $kind, 'icon' => self::ICONS[$kind], 'open' => $state['open'] === $path,
                'auto' => $auto, 'raw' => $raw, 'value' => $value,
            ];

            if ($kind === 'page') {
                $ref = is_string($value['page'] ?? null) && $value['page'] !== '' ? 'u:'.$value['page'] : (is_string($value['path'] ?? null) ? 'p:'.$value['path'] : null);
                $page = $ref !== null ? ($pages[$ref] ?? null) : null;
                $view['page'] = $page;
                $view['pageGone'] = $ref !== null && $page === null;
                $view['pageShown'] = substr((string) $ref, 2);
                $view['searching'] = $ref === null || ($state['searching'][$fid] ?? false);
                if ($view['searching']) {
                    $query = mb_substr(trim($state['queries'][$fid] ?? ''), 0, 100);
                    $searches[$query] ??= $this->search($query, $locale);
                    $view['query'] = $query;
                    $view['results'] = $searches[$query];
                }
            }
            if ($kind === 'anchor') {
                $view['anchors'] = $anchors;
            }
            if ($kind === 'tel' || $kind === 'zalo') {
                $digits = (string) preg_replace('/\D/', '', Cast::string($value['phone'] ?? ''));
                $siteText ??= $sitePhone();
                $siteDigits = (string) preg_replace('/\D/', '', $siteText);
                // shown the way people read a number: 0915 668 383
                $view['sitePhone'] = preg_match('/\A0\d{9}\z/', $siteDigits) === 1
                    ? substr($siteDigits, 0, 4).' '.substr($siteDigits, 4, 3).' '.substr($siteDigits, 7)
                    : $siteText;
                $view['siteSame'] = $siteDigits !== '' && $digits === $siteDigits;
            }
            if ($kind === 'file') {
                $key = Cast::string($value['file'] ?? '');
                $view['file'] = $key !== '' ? ($files[$key] ?? null) : null;
                $view['fileGone'] = $key !== '' && $view['file'] === null;
            }

            $problem = $this->problem($view, $anchors);
            $view['error'] = $problem !== null && ($problem['now'] || ($state['touched'][$fid] ?? false)) ? $problem['text'] : null;
            $views[$path] = $view;
        }

        return $views;
    }

    /**
     * Documents of the library for the "Chọn tệp cho khách tải" dialog, newest first.
     *
     * @return list<array{key: string, name: string, meta: string, icon: string}>
     */
    public function documents(int $limit = 60): array
    {
        $docs = [];
        $rows = Media::query()->whereIn('mime', ButtonLink::DOCUMENT_MIMES)->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->get(['storage_key', 'original_filename', 'mime', 'size', 'created_at']);
        foreach ($rows as $media) {
            $docs[] = $this->document($media);
        }

        return $docs;
    }

    /**
     * The problem with a button's value in plain words, and whether it shows at once (a page, a
     * part or a file that has gone; a link that is unsafe) rather than after the field is left.
     *
     * @param  array<string, mixed>  $view
     * @param  list<array{id: string, label: string, meta: string, icon: string}>  $anchors
     * @return array{text: string, now: bool}|null
     */
    private function problem(array $view, array $anchors): ?array
    {
        /** @var array<string, mixed> $value */
        $value = $view['value'];
        $text = static fn (string $field): string => trim(Cast::string($value[$field] ?? ''));

        switch ($view['kind']) {
            case 'page':
                if ($view['pageGone'] === true) {
                    return $this->say('btn_err_page_gone', true, ['page' => Cast::string($view['pageShown'])]);
                }

                return $view['page'] === null ? $this->say('btn_err_page_none') : null;
            case 'url':
                $url = $text('url');
                if ($url === '') {
                    return $this->say('btn_err_url_none');
                }
                $folded = strtolower((string) preg_replace('/[\s\x00-\x1F]+/', '', $url));
                foreach (['javascript:', 'data:', 'vbscript:'] as $scheme) {
                    if (str_starts_with($folded, $scheme)) {
                        return $this->say('btn_err_url_unsafe', true);
                    }
                }
                if (str_starts_with($url, '/') && SafeLink::isSitePath($url)) {
                    return $this->say('btn_err_url_internal', true);
                }

                return ButtonLink::external($url) === null || preg_match('~\Ahttps?://[^\s/.]+\.[^\s]{2,}~i', $url) !== 1 ? $this->say('btn_err_url_invalid') : null;
            case 'anchor':
                $anchor = $text('anchor');
                if ($anchor === '') {
                    return $this->say('btn_err_anchor_none');
                }

                return in_array($anchor, array_column($anchors, 'id'), true) ? null : $this->say('btn_err_anchor_gone', true, ['anchor' => $anchor]);
            case 'tel':
                $phone = $text('phone');
                if ($phone === '') {
                    return $this->say('btn_err_tel_none');
                }

                return ButtonLink::tel($phone) === null ? $this->say('btn_err_tel_invalid') : null;
            case 'zalo':
                $phone = $text('phone');
                if ($phone === '') {
                    return $this->say('btn_err_zalo_none');
                }
                if (ButtonLink::zalo($phone) !== null) {
                    return null;
                }

                return $this->say(stripos($phone, 'zalo.me') !== false ? 'btn_err_zalo_link' : 'btn_err_zalo_invalid');
            case 'mail':
                $email = $text('email');
                if ($email === '') {
                    return $this->say('btn_err_mail_none');
                }

                return ButtonLink::mail($email, null) === null ? $this->say('btn_err_mail_invalid') : null;
            default:
                if ($view['fileGone'] === true) {
                    return $this->say('btn_err_file_gone', true);
                }

                return $view['file'] === null ? $this->say('btn_err_file_none') : null;
        }
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{text: string, now: bool}
     */
    private function say(string $key, bool $now = false, array $replace = []): array
    {
        return ['text' => __('entries.'.$key, $replace), 'now' => $now];
    }

    /**
     * The pages named by ulid ("u:<ulid>") and the older addresses that are pages ("p:<path>"), with
     * their title, kind and address in $locale. Trashed entries are left out (gone).
     *
     * @param  list<string>  $ulids
     * @param  list<string>  $paths
     * @return array<string, array{ulid: string, title: string, meta: string, icon: string}>
     */
    private function pages(array $ulids, array $paths, string $locale): array
    {
        if ($ulids === [] && $paths === []) {
            return [];
        }

        $byPath = [];
        if ($paths !== []) {
            foreach (SiteRoute::query()->where('locale', $locale)->whereIn('path', $paths)->whereNotNull('entry_id')->get(['path', 'entry_id']) as $route) {
                $byPath[(int) $route->entry_id] = $route->path;
            }
        }

        $rows = $this->entryRows($locale)
            ->where(fn (Builder $query) => $query->whereIn('entries.ulid', $ulids)->orWhereIn('entries.id', array_keys($byPath)))
            ->get();

        $found = [];
        foreach ($rows as $row) {
            $page = $this->pageRow($row);
            $found['u:'.$page['ulid']] = $page;
            $id = Cast::int($row->getAttribute('target_id'));
            if (isset($byPath[$id])) {
                $found['p:'.$byPath[$id]] = $page;
            }
        }

        return $found;
    }

    /**
     * Published pages and posts whose title holds $query (accents and case ignored), pages first,
     * newest first; the newest ones with nothing typed. One query.
     *
     * @return list<array{ulid: string, title: string, meta: string, icon: string}>
     */
    private function search(string $query, string $locale): array
    {
        $rows = $this->entryRows($locale)->where('entries.status', 'published');
        $needle = VietnameseFold::text($query);
        if ($needle !== '') {
            $rows->whereRaw(VietnameseFold::sql('entry_translations.title').' LIKE ?', [VietnameseFold::like($needle)]);
        }

        $found = [];
        foreach ($rows->orderByRaw("entries.type = 'page' desc")->orderByDesc('entries.id')->limit(self::RESULTS)->get() as $row) {
            $found[] = $this->pageRow($row);
        }

        return $found;
    }

    /**
     * @return Builder<EntryTranslation>
     */
    private function entryRows(string $locale): Builder
    {
        return EntryTranslation::query()
            ->join('entries', 'entries.id', '=', 'entry_translations.entry_id')
            ->leftJoin('routes', fn (JoinClause $join) => $join->on('routes.entry_id', '=', 'entries.id')->where('routes.locale', '=', $locale))
            ->whereIn('entries.type', ['page', 'post'])
            ->whereNull('entries.deleted_at')
            ->where('entry_translations.locale', $locale)
            ->select(['entries.id as target_id', 'entries.ulid as target_ulid', 'entries.type as target_type', 'entry_translations.title as target_title', 'routes.path as target_path']);
    }

    /**
     * @return array{ulid: string, title: string, meta: string, icon: string}
     */
    private function pageRow(EntryTranslation $row): array
    {
        $post = $row->getAttribute('target_type') === 'post';
        $path = $row->getAttribute('target_path');

        return [
            'ulid' => Cast::string($row->getAttribute('target_ulid')),
            'title' => Cast::string($row->getAttribute('target_title')),
            'meta' => __($post ? 'entries.btn_kind_post' : 'entries.btn_kind_page_noun').(is_string($path) ? ' · '.$path : ''),
            'icon' => $post ? 'ri-article' : 'ri-file-text',
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{key: string, name: string, meta: string, icon: string}>
     */
    private function files(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $found = [];
        foreach (Media::query()->whereIn('storage_key', $keys)->whereIn('mime', ButtonLink::DOCUMENT_MIMES)->get(['storage_key', 'original_filename', 'mime', 'size', 'created_at']) as $media) {
            $found[$media->storage_key] = $this->document($media);
        }

        return $found;
    }

    /**
     * @return array{key: string, name: string, meta: string, icon: string}
     */
    private function document(Media $media): array
    {
        $ext = strtoupper(Media::MIME_EXT_LABELS[$media->mime] ?? '');
        $bytes = $media->size;
        $size = $bytes >= 1_048_576
            ? number_format($bytes / 1_048_576, 1, ',', '.').' MB'
            : number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.').' KB';
        $date = $media->created_at?->format('d/m/Y');

        return [
            'key' => $media->storage_key,
            'name' => $media->original_filename ?? basename($media->storage_key),
            'meta' => $ext.' · '.$size.($date !== null ? ' · '.__('entries.btn_file_uploaded', ['date' => $date]) : ''),
            'icon' => self::DOC_ICONS[$media->mime] ?? 'ri-file',
        ];
    }

    /**
     * The parts of the page a button can scroll to: the blocks with an anchor, named by heading.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<string, array<array-key, mixed>>  $schemas
     * @return list<array{id: string, label: string, meta: string, icon: string}>
     */
    private function anchors(array $blocks, array $schemas): array
    {
        $anchors = [];
        foreach ($blocks as $block) {
            $props = is_array($block) && is_array($block['props'] ?? null) ? $block['props'] : [];
            $id = $props['anchor_id'] ?? null;
            $type = is_array($block) ? Cast::string($block['type'] ?? '') : '';
            if (! is_string($id) || preg_match(StyleProps::ANCHOR_PATTERN, $id) !== 1 || ! isset($schemas[$type])) {
                continue;
            }
            $heading = trim(Cast::string($props['heading'] ?? $props['title'] ?? ''));
            $anchors[] = [
                'id' => $id,
                'label' => $heading !== '' ? $heading : $type,
                'meta' => ($heading !== '' ? $type.' · ' : '').__('entries.btn_anchor_name', ['id' => $id]),
                'icon' => 'ri-hashtag',
            ];
        }

        return $anchors;
    }
}
