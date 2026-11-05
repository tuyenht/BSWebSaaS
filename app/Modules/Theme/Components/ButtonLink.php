<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

use App\Modules\Shared\Support\SafeLink;
use App\Modules\Theme\Chrome\FloatingContact;
use Closure;

/**
 * What a block button does when pressed ("Khi bấm nút"). A url prop the schema marks `action`
 * (its value names the prop holding the button's label) is a button link: next to it, under
 * `<prop>_do`, the editor stores one typed value {k, …}:
 *   page   {page: entry ulid}            — resolved to the entry's current address at render, so a
 *                                           renamed slug never breaks the button
 *   url    {url: http(s) address, blank: open in a new tab (default yes)}
 *   anchor {anchor: a block's anchor on the same page}
 *   tel    {phone}                       — tel: from the number (FloatingContact's phone rules)
 *   zalo   {phone: a number or a zalo.me link} — zalo.me (FloatingContact's Zalo rules)
 *   mail   {email, subject?}             — mailto:, the subject encoded
 *   file   {file: a media storage key}   — a document of the site's library, resolved at render
 * An older body holds a plain address in the prop itself; it keeps working (legacy()). Every
 * button template reads its link through of(): one resolver, and a value that does not resolve
 * gives no link (never `#` or the raw text). A blank required field is allowed at save (the
 * button then has no link); a filled one that is not valid is refused (problem()).
 */
final class ButtonLink
{
    /** Suffix of the prop that holds a button's typed value. */
    public const string SUFFIX = '_do';

    /** The kinds, in the order the editor lists them. */
    public const array KINDS = ['page', 'url', 'anchor', 'tel', 'zalo', 'mail', 'file'];

    /** The library files a "Tải tệp" button may offer (Media::MIME_EXT_LABELS: PDF, Word, Excel). */
    public const array DOCUMENT_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Longest mail subject kept. */
    public const int SUBJECT_MAX = 150;

    /**
     * Where the renderer puts a page's or a file's resolved address inside the value. Never
     * stored: problem() refuses a field the kind does not name.
     */
    public const string RESOLVED = 'href';

    /** The fields each kind holds besides `k`. */
    private const array FIELDS = [
        'page' => ['page'],
        'url' => ['url', 'blank'],
        'anchor' => ['anchor'],
        'tel' => ['phone'],
        'zalo' => ['phone'],
        'mail' => ['email', 'subject'],
        'file' => ['file'],
    ];

    /** The label prop of a button link spec, or null when the spec is not one. */
    public static function labelProp(mixed $spec): ?string
    {
        return is_array($spec) && ($spec['type'] ?? null) === 'url' && is_string($spec['action'] ?? null) ? $spec['action'] : null;
    }

    /**
     * Why a stored value cannot be saved, or null when it can: 'kind' (not an object of a known
     * kind, or a field the kind does not hold) or the kind whose filled value is not valid.
     */
    public static function problem(mixed $do): ?string
    {
        if (! is_array($do) || ! is_string($do['k'] ?? null) || ! isset(self::FIELDS[$do['k']])) {
            return 'kind';
        }
        $kind = $do['k'];
        foreach (array_keys($do) as $field) {
            if ($field !== 'k' && ! in_array($field, self::FIELDS[$kind], true)) {
                return 'kind';
            }
        }
        foreach (self::FIELDS[$kind] as $field) {
            $value = $do[$field] ?? null;
            if ($value !== null && ! ($field === 'blank' ? is_bool($value) : is_string($value))) {
                return $kind;
            }
        }

        $text = static fn (string $field): string => is_string($do[$field] ?? null) ? $do[$field] : '';
        $filled = static fn (string $field): bool => trim($text($field)) !== '';
        $valid = match ($kind) {
            'page' => ! $filled('page') || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $text('page')) === 1,
            'url' => ! $filled('url') || self::external($text('url')) !== null,
            'anchor' => ! $filled('anchor') || self::anchor($text('anchor')) !== null,
            'tel' => ! $filled('phone') || self::tel($text('phone')) !== null,
            'zalo' => ! $filled('phone') || self::zalo($text('phone')) !== null,
            'mail' => (! $filled('email') || self::mail($text('email'), null) !== null)
                && mb_strlen($text('subject')) <= self::SUBJECT_MAX
                && preg_match('/\p{C}/u', $text('subject')) !== 1,
            default => ! $filled('file') || self::isKey($text('file')),
        };

        return $valid ? null : $kind;
    }

    /**
     * The link a button opens: href, whether it opens in a new tab, whether it is a download. Null
     * when there is none — no label-only `#`, no raw text.
     *
     * @param  array<array-key, mixed>  $props
     * @return array{href: string, blank: bool, download: bool}|null
     */
    public static function of(array $props, string $key): ?array
    {
        $do = $props[$key.self::SUFFIX] ?? null;
        if (is_array($do)) {
            return self::fromValue($do);
        }

        $href = self::legacy($props[$key] ?? null);

        return $href === null ? null : ['href' => $href, 'blank' => false, 'download' => false];
    }

    /**
     * @param  array<array-key, mixed>  $do
     * @return array{href: string, blank: bool, download: bool}|null
     */
    public static function fromValue(array $do): ?array
    {
        $resolved = $do[self::RESOLVED] ?? null;
        unset($do[self::RESOLVED]);
        if (self::problem($do) !== null) {
            return null;
        }

        $kind = $do['k'];
        $text = static fn (string $field): string => is_string($do[$field] ?? null) ? $do[$field] : '';
        $href = match ($kind) {
            'page' => is_string($resolved) && SafeLink::isSitePath($resolved) ? $resolved : null,
            'file' => is_string($resolved) ? SafeLink::href($resolved) : null,
            'url' => self::external($text('url')),
            'anchor' => self::anchor($text('anchor')),
            'tel' => self::tel($text('phone')),
            'zalo' => self::zalo($text('phone')),
            default => self::mail($text('email'), $text('subject')),
        };

        return $href === null ? null : [
            'href' => $href,
            'blank' => $kind === 'url' && ($do['blank'] ?? true) !== false,
            'download' => $kind === 'file',
        ];
    }

    /**
     * An older plain address, drawn as the templates drew it before typed values: anything
     * SafeLink::plain lets through (a relative path, `www.…`, `#anchor`, `sms:`…). A `tel:` is
     * rebuilt from its digits when it has them; a `mailto:` keeps only a valid address (no `?`, `&`,
     * `%` before the `@`) and its subject, or gives no link.
     */
    public static function legacy(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $value = trim($raw);
        $lower = strtolower($value);

        if (str_starts_with($lower, 'tel:')) {
            $tel = SafeLink::tel(rawurldecode(substr($value, 4)));
            if ($tel !== null) {
                return $tel;
            }
        }
        if (str_starts_with($lower, 'mailto:')) {
            [$address, $query] = array_pad(explode('?', substr($value, 7), 2), 2, '');
            parse_str($query, $fields);

            return self::mail(rawurldecode($address), is_string($fields['subject'] ?? null) ? $fields['subject'] : null);
        }

        return SafeLink::plain($value);
    }

    /**
     * The kind the editor sorts an older plain address into, with its value (the page kind holds the
     * address as `path`, to be matched against the site's pages), as the design's picker reads it.
     *
     * @return array<string, string|bool>
     */
    public static function sortLegacy(string $raw): array
    {
        $value = trim($raw);
        $lower = strtolower($value);

        return match (true) {
            $value === '' => ['k' => 'page'],
            str_starts_with($lower, 'tel:') => ['k' => 'tel', 'phone' => rawurldecode(substr($value, 4))],
            str_starts_with($lower, 'mailto:') => self::sortMail(substr($value, 7)),
            preg_match('~\Ahttps?://(www\.)?zalo\.me/~i', $value) === 1 => ['k' => 'zalo', 'phone' => $value],
            $value[0] === '#' => ['k' => 'anchor', 'anchor' => substr($value, 1)],
            $value[0] === '/' && ! str_starts_with($value, '//') => ['k' => 'page', 'path' => $value],
            default => ['k' => 'url', 'url' => $value, 'blank' => true],
        };
    }

    /**
     * The page ulids and file keys the page's button links name, for one lookup each.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @return array{pages: list<string>, files: list<string>}
     */
    public static function refs(array $props, array $schema): array
    {
        $refs = ['pages' => [], 'files' => []];
        self::walk($props, $schema, function (array $do) use (&$refs): array {
            $kind = $do['k'] ?? null;
            $ref = $kind === 'page' ? ($do['page'] ?? null) : ($kind === 'file' ? ($do['file'] ?? null) : null);
            if (is_string($ref) && $ref !== '') {
                $refs[$kind === 'page' ? 'pages' : 'files'][] = $ref;
            }

            return $do;
        });

        return ['pages' => array_values(array_unique($refs['pages'])), 'files' => array_values(array_unique($refs['files']))];
    }

    /**
     * $props with every page and file button given its resolved address (or none), from the page's
     * one lookup; any address already in the value is dropped first.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @param  array<string, string>  $pages  entry ulid => public path
     * @param  array<string, string>  $files  storage key => file address
     * @return array<array-key, mixed>
     */
    public static function resolve(array $props, array $schema, array $pages, array $files): array
    {
        return self::walk($props, $schema, static function (array $do) use ($pages, $files): array {
            unset($do[self::RESOLVED]);
            $kind = $do['k'] ?? null;
            $ref = $kind === 'page' ? ($do['page'] ?? null) : ($kind === 'file' ? ($do['file'] ?? null) : null);
            if (is_string($ref)) {
                $found = $kind === 'page' ? ($pages[$ref] ?? null) : ($files[$ref] ?? null);
                if ($found !== null) {
                    $do[self::RESOLVED] = $found;
                }
            }

            return $do;
        });
    }

    /**
     * Apply $fn to every stored button value, through lists of objects like the other schema walks.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @param  Closure(array<array-key, mixed>): array<array-key, mixed>  $fn
     * @return array<array-key, mixed>
     */
    public static function walk(array $props, array $schema, Closure $fn): array
    {
        foreach ($schema as $prop => $spec) {
            if (self::labelProp($spec) !== null) {
                $key = $prop.self::SUFFIX;
                if (is_array($props[$key] ?? null)) {
                    $props[$key] = $fn($props[$key]);
                }

                continue;
            }

            $of = is_array($spec) && ($spec['type'] ?? null) === 'list' ? ($spec['of'] ?? null) : null;
            if (! is_array($of) || isset($of['type']) || ! is_array($props[$prop] ?? null)) {
                continue;
            }
            $items = $props[$prop];
            foreach ($items as $i => $item) {
                if (is_array($item)) {
                    $items[$i] = self::walk($item, $of, $fn);
                }
            }
            $props[$prop] = $items;
        }

        return $props;
    }

    /** An http(s) address on another site; a path of this site belongs to the page kind. */
    public static function external(string $url): ?string
    {
        $url = trim($url);

        return str_starts_with($url, '/') ? null : SafeLink::href($url);
    }

    /** `#anchor` for a valid block anchor. */
    public static function anchor(string $anchor): ?string
    {
        return preg_match(StyleProps::ANCHOR_PATTERN, $anchor) === 1 ? '#'.$anchor : null;
    }

    public static function tel(string $phone): ?string
    {
        $clean = FloatingContact::clean('phone', $phone);

        return $clean === null ? null : FloatingContact::href('phone', $clean);
    }

    public static function zalo(string $phone): ?string
    {
        $clean = FloatingContact::clean('zalo', $phone);

        return $clean === null ? null : FloatingContact::href('zalo', $clean);
    }

    /** mailto: for a valid address, the subject (if any) percent-encoded. */
    public static function mail(string $email, ?string $subject): ?string
    {
        $link = SafeLink::mailto($email);
        if ($link === null) {
            return null;
        }
        $subject = trim((string) $subject);
        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX || preg_match('/\p{C}/u', $subject) === 1) {
            return $link;
        }

        return $link.'?subject='.rawurlencode($subject);
    }

    /** A storage key as the library writes it: a relative path, nothing a browser reads as an address. */
    private static function isKey(string $key): bool
    {
        return strlen($key) <= SafeLink::MAX_LENGTH && ! str_contains($key, '://')
            && preg_match('~\A[\w.-]+(?:/[\w.-]+)*\z~', $key) === 1;
    }

    /**
     * @return array<string, string>
     */
    private static function sortMail(string $rest): array
    {
        [$address, $query] = array_pad(explode('?', $rest, 2), 2, '');
        parse_str($query, $fields);

        return ['k' => 'mail', 'email' => rawurldecode($address), 'subject' => is_string($fields['subject'] ?? null) ? $fields['subject'] : ''];
    }
}
