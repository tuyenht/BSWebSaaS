<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Admin\Support\Settings\HeadingDecorInput;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaHtml;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Theme\Rendering\LayoutSlots;
use App\Modules\Theme\Rendering\RichHtml;
use App\Modules\Theme\Rendering\TemplateCatalog;
use App\Modules\Theme\Theming\ThemeResolver;
use Illuminate\Support\Facades\Validator;

/**
 * Everything a content pack must satisfy before any planning, with no database read: the header,
 * key shapes and uniqueness, locale maps, the "unverified ⇒ draft" rule, every page and post body
 * through the editor's own ValidPageJson, header/footer/theme settings through the storefront's
 * own readers (a value the reader would drop is an error here, never a silent drop), category
 * order and references, menus, redirects and image slots (a named file must exist and be an image
 * by its magic bytes). Returns every problem at once.
 */
final readonly class PackValidator
{
    public const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Varchar(255) columns the loader writes. */
    private const int TEXT_MAX = 255;

    private const array STATUSES = ['published', 'draft'];

    private const array PROFILE_FIELDS = [
        'legal_name', 'brand_name', 'tax_code', 'business_license_no', 'representative', 'email', 'phone',
        'hotline', 'address_line', 'ward', 'district', 'province', 'postal_code', 'license_issued_at',
        'social_links', 'moit_registered', 'moit_url',
    ];

    private const array PROFILE_LOCALIZED = [
        'brand_name', 'short_description', 'description', 'address_line', 'legal_name', 'tagline',
        'representative', 'opening_hours_text',
    ];

    private const array DYNAMIC_LINKS = ['home'];

    /** The setting groups and names a pack may write; everything else is the site's own. */
    private const array SETTINGS = [
        'theme' => ['tokens', 'heading_decor'],
        'storefront' => ['header', 'footer', 'floating_contact', 'layout_blocks'],
        'general' => ['brand'],
    ];

    private const string LINK_RULE = 'a site path (/…, never //host, a backslash or a space) or an http(s) link';

    private const int MENU_DEPTH = 3;

    /** Image magic bytes → MIME (the upload whitelist's raster types). */
    private const array MAGIC = [
        "\xFF\xD8\xFF" => 'image/jpeg',
        "\x89PNG\r\n\x1A\n" => 'image/png',
    ];

    public function __construct(
        private ValidPageJson $pageJson,
        private RichTextSanitizer $sanitizer,
        private ComponentRegistry $components,
    ) {}

    /**
     * A link a pack may write: a path on the site (SafeLink::isSitePath) or an exact http(s) link
     * with a host — never a protocol-relative `//host`, `/\host`, another scheme, or padding.
     */
    public static function isLink(mixed $value): bool
    {
        return SafeLink::isSitePath($value) || self::isWebLink($value);
    }

    /** An exact http(s) link with a host (no site path). */
    public static function isWebLink(mixed $value): bool
    {
        return is_string($value) && ! str_starts_with($value, '/') && SafeLink::href($value) === $value;
    }

    /**
     * @return list<string> every problem found; empty = valid
     */
    public function validate(Pack $pack, string $folder): array
    {
        $errors = [];
        $this->header($pack, $folder, $errors);

        $seen = [];
        $taxonomies = [];
        $pages = [];
        foreach ($pack->items as $item) {
            if (isset($seen[$item->key])) {
                $errors[] = "{$item->key}: the key is used twice.";
            }
            $seen[$item->key] = true;

            $at = $item->key;
            match ($item->kind) {
                'setting' => $this->setting($pack, $item, $errors),
                'profile' => $this->profile($pack, $item, $errors),
                'taxonomy' => $this->taxonomy($pack, $item, $taxonomies, $errors),
                'product' => $this->product($pack, $item, $taxonomies, $errors),
                'post' => $this->post($pack, $item, $taxonomies, $errors),
                'page' => $this->page($pack, $item, $pages, $errors),
                'menu' => $this->menu($pack, $item, $errors),
                'redirect' => $this->redirect($pack, $item, $errors),
                default => $errors[] = "{$at}: unknown kind.",
            };
            if ($item->kind === 'taxonomy') {
                $taxonomies[$item->key] = true;
            }
            if ($item->kind === 'page') {
                $pages[$item->key] = true;
            }

            foreach (self::slotsIn($item->data) as $slot) {
                if (! isset($pack->slots[$slot])) {
                    $errors[] = "{$at}: image slot '{$slot}' is not in media.json.";
                } elseif (self::isDocument($pack->slots[$slot])) {
                    $errors[] = "{$at}: media slot '{$slot}' is a document, not an image.";
                }
            }
            $documents = self::documentsIn($item->data);
            if ($documents !== [] && ! in_array($item->kind, ['page', 'post'], true)) {
                $errors[] = "{$at}: a file download button is supported only in a page or post body.";
            }
            foreach ($documents as $slot) {
                if (! isset($pack->slots[$slot])) {
                    $errors[] = "{$at}: document slot '{$slot}' is not in media.json.";
                } elseif (! self::isDocument($pack->slots[$slot])) {
                    $errors[] = "{$at}: media slot '{$slot}' is an image, not a document.";
                }
            }
        }

        foreach ($pack->slots as $name => $slot) {
            $this->slot($pack, $name, $slot, $errors);
        }

        return array_values(array_unique($errors));
    }

    /**
     * Every `{"media": slot}` image reference anywhere in a value (a file button's document is not one).
     *
     * @return list<string>
     */
    public static function slotsIn(mixed $value): array
    {
        if (! is_array($value) || self::isFileButton($value) || self::isFileLabel($value)) {
            return [];
        }
        if (self::isMediaRef($value)) {
            return [Cast::string($value['media'])];
        }

        $slots = [];
        foreach ($value as $child) {
            array_push($slots, ...self::slotsIn($child));
        }

        return $slots;
    }

    /** @phpstan-assert-if-true array{media: string} $value */
    public static function isMediaRef(mixed $value): bool
    {
        return is_array($value) && count($value) === 1 && is_string($value['media'] ?? null);
    }

    /**
     * Every document slot a file button names anywhere in a value: the button's
     * `{"k": "file", "file": {"media": slot}}` and its label's `{"text": …, "type_and_size_of": slot}`.
     *
     * @return list<string>
     */
    public static function documentsIn(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        if (self::isFileButton($value)) {
            return [$value['file']['media']];
        }
        if (self::isFileLabel($value)) {
            return [$value['type_and_size_of']];
        }

        $slots = [];
        foreach ($value as $child) {
            array_push($slots, ...self::documentsIn($child));
        }

        return array_values(array_unique($slots));
    }

    /**
     * A download button of a page body that names a pack document: `{"k": "file", "file": {"media": slot}}`.
     * It loads as the button the admin stores, holding the document's storage key.
     *
     * @phpstan-assert-if-true array{k: 'file', file: array{media: string}} $value
     */
    public static function isFileButton(mixed $value): bool
    {
        return is_array($value) && count($value) === 2 && ($value['k'] ?? null) === 'file' && self::isMediaRef($value['file'] ?? null);
    }

    /**
     * A button label followed by a pack document's type and size, read from the uploaded file:
     * `{"text": "Tải hồ sơ", "type_and_size_of": slot}` loads as "Tải hồ sơ (PDF · 9,0 MB)".
     *
     * @phpstan-assert-if-true array{text: string, type_and_size_of: string} $value
     */
    public static function isFileLabel(mixed $value): bool
    {
        return is_array($value) && count($value) === 2 && is_string($value['text'] ?? null) && is_string($value['type_and_size_of'] ?? null);
    }

    /** @param list<string> $errors */
    private function header(Pack $pack, string $folder, array &$errors): void
    {
        if ($pack->code !== $folder) {
            $errors[] = "pack.json: code '{$pack->code}' must equal the folder name '{$folder}'.";
        }
        if ($pack->version < 1) {
            $errors[] = 'pack.json: version must be a whole number from 1.';
        }
        if ($pack->hosts === []) {
            $errors[] = 'pack.json: hosts must list at least one hostname.';
        }
        foreach ($pack->hosts as $host) {
            if (preg_match('/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', $host) !== 1) {
                $errors[] = "pack.json: '{$host}' is not a hostname.";
            }
        }
        if ($pack->locales === [] || ! in_array($pack->defaultLocale, $pack->locales, true)) {
            $errors[] = 'pack.json: locales must list the default_locale.';
        }
        foreach ($pack->locales as $locale) {
            if (preg_match('/\A[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\z/', $locale) !== 1) {
                $errors[] = "pack.json: '{$locale}' is not a language code.";
            }
        }
    }

    /** @param list<string> $errors */
    private function setting(Pack $pack, PackItem $item, array &$errors): void
    {
        if (preg_match('/\Asetting:([a-z][a-z0-9_]{0,39})\.([a-z][a-z0-9_]{0,39})\z/', $item->key, $m) !== 1) {
            $errors[] = "{$item->key}: a setting key is setting:<group>.<name>.";

            return;
        }
        if ($m[1] === Ledger::GROUP) {
            $errors[] = "{$item->key}: the '".Ledger::GROUP."' group is the loader's own.";
        } elseif (! in_array($m[2], self::SETTINGS[$m[1]] ?? [], true)) {
            $allowed = [];
            foreach (self::SETTINGS as $group => $names) {
                foreach ($names as $name) {
                    $allowed[] = "setting:{$group}.{$name}";
                }
            }
            $errors[] = "{$item->key}: the loader writes only ".implode(', ', $allowed).'; any other setting stays the site\'s own.';
        }

        $value = $item->data['value'] ?? null;
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = "{$item->key}: value must be a JSON object.";

            return;
        }

        if (array_key_exists('managed_keys', $item->data)) {
            $managed = $item->data['managed_keys'];
            if (! is_array($managed) || ! array_is_list($managed) || $managed === [] || Cast::strings($managed) !== $managed) {
                $errors[] = "{$item->key}: managed_keys must be a non-empty list of names.";
            } else {
                foreach ($managed as $name) {
                    if (! array_key_exists($name, $value)) {
                        $errors[] = "{$item->key}: managed key '{$name}' has no value.";
                    }
                }
            }
        }

        foreach ($value as $name => $sub) {
            if (is_array($sub) && array_key_exists('media', $sub) && ! self::isMediaRef($sub)) {
                $errors[] = "{$item->key}: '{$name}' is not a valid image reference.";
            }
        }
        $this->settingLinks($item->key, $value, '', $errors);

        $default = $pack->defaultLocale;
        match ($item->key) {
            'setting:theme.tokens' => $this->tokens($item->key, $value, $errors),
            'setting:storefront.header' => $this->chromeHeader($item->key, $value, $pack, $errors),
            'setting:storefront.footer' => $this->footer($item->key, $value, $pack, $errors),
            'setting:theme.heading_decor' => $this->headingDecor($item->key, $value, $item->managedKeys(), $errors),
            'setting:storefront.floating_contact' => $this->floatingContact($item->key, $value, $pack, $errors),
            'setting:storefront.layout_blocks' => $this->layoutBlocks($item->key, $value, $pack, $errors),
            default => null,
        };
        unset($default);
    }

    /**
     * Every string under a `url`, `href` or `*_url` key, at any depth, must be a link a pack may
     * write — whatever the reader of that setting later does with it.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $errors
     */
    private function settingLinks(string $at, array $value, string $prefix, array &$errors): void
    {
        foreach ($value as $name => $sub) {
            $path = $prefix.$name;
            if (is_array($sub)) {
                $this->settingLinks($at, $sub, $path.'.', $errors);
            } elseif (is_string($name) && ($name === 'url' || $name === 'href' || str_ends_with($name, '_url')) && ! self::isLink($sub)) {
                $errors[] = "{$at}: '{$path}' must be ".self::LINK_RULE.'.';
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $errors
     */
    private function tokens(string $at, array $value, array &$errors): void
    {
        foreach ($value as $key => $token) {
            if (! ThemeResolver::acceptsToken((string) $key, $token)) {
                $errors[] = "{$at}: token '{$key}' would be dropped by the theme (name [a-z0-9-], CSS-safe value).";
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $errors
     */
    private function chromeHeader(string $at, array $value, Pack $pack, array &$errors): void
    {
        $stored = Cast::arr(Cast::arr($value['top_bar'] ?? null)['items'] ?? null);
        foreach ($pack->locales as $locale) {
            $read = StorefrontChrome::header($value, $locale, $pack->defaultLocale);
            if ($read === null || count($read['top_bar']) !== count($stored)) {
                $errors[] = "{$at}: a top-bar item would be dropped in '{$locale}' (bad kind, empty text, unsafe link, or more than ".StorefrontChrome::MAX_TOP_BAR_ITEMS.').';

                continue;
            }
            foreach ($read['top_bar'] as $index => $shown) {
                if (in_array($shown['kind'], ['phone', 'email'], true) && $shown['href'] === null) {
                    $errors[] = "{$at}: top-bar item ".($index + 1)." has a {$shown['kind']} value the header cannot link.";
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $errors
     */
    private function footer(string $at, array $value, Pack $pack, array &$errors): void
    {
        $mapHosts = Cast::strings(config('cms.map_hosts'));
        $factories = Cast::arr($value['factories'] ?? null);
        foreach ($pack->locales as $locale) {
            $read = StorefrontChrome::footer($value, $locale, $pack->defaultLocale, $mapHosts);
            if ($read === null) {
                continue;
            }
            if (count($read['factories']) !== count($factories)) {
                $errors[] = "{$at}: a factory would be dropped in '{$locale}' (no title, or more than ".StorefrontChrome::MAX_FACTORIES.').';
            }
            foreach (array_values($factories) as $index => $factory) {
                $factory = Cast::arr($factory);
                if (isset($factory['map_url']) && ($read['factories'][$index]['map_url'] ?? null) === null) {
                    $errors[] = "{$at}: factory ".($index + 1).' map_url is not an allowed map link.';
                }
                if (isset($factory['area_m2']) && ($read['factories'][$index]['area'] ?? null) === null) {
                    $errors[] = "{$at}: factory ".($index + 1).' area_m2 must be a whole number of m².';
                }
            }
            $copyright = Cast::arr($value['copyright'] ?? null);
            if (isset($copyright['start_year']) && $read['start_year'] === null) {
                $errors[] = "{$at}: copyright.start_year is not a year.";
            }
            $credit = Cast::arr($value['credit'] ?? null);
            if ($credit !== [] && ($read['credit'] === null || (isset($credit['url']) && $read['credit']['url'] === null))) {
                $errors[] = "{$at}: the credit needs a label and a safe web link.";
            }
            if (isset($value['about']) && $read['about'] === null) {
                $errors[] = "{$at}: about is empty in '{$locale}'.";
            }
            if (isset($value['columns_menu_region']) && $read['region'] !== $value['columns_menu_region']) {
                $errors[] = "{$at}: columns_menu_region is not a region name.";
            }
        }
    }

    /**
     * The admin's own check of the heading decoration. A mask picture is a media key of the site's
     * library, which a pack cannot name, so it is left out.
     *
     * With managed keys only those are the pack's; a custom colour whose hex the pack leaves to the
     * live value is judged when the plan is made (the merged whole), not here.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>|null  $managed
     * @param  list<string>  $errors
     */
    private function headingDecor(string $at, array $value, ?array $managed, array &$errors): void
    {
        if ($value === []) {
            $errors[] = "{$at}: value must not be empty (an empty heading decoration removes the setting; leave the item out instead).";

            return;
        }
        if (($value['mask_media'] ?? '') !== '') {
            $errors[] = "{$at}: mask_media is a picture of the site's own library; a pack cannot set it.";

            return;
        }
        $probe = array_combine(array_map('strval', array_keys($value)), $value);
        if ($managed !== null) {
            $probe = array_intersect_key($probe, array_flip($managed));
            if (($probe['color'] ?? null) === 'custom') {
                $probe += ['custom_color' => '#000000'];
                $probe += ['custom_color_2' => '#000000'];
            }
        }
        try {
            HeadingDecorInput::checked($probe);
        } catch (SettingsChangeRejected $e) {
            $errors[] = "{$at}: '{$e->field}' is not accepted ({$e->reason}).";
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $errors
     */
    private function floatingContact(string $at, array $value, Pack $pack, array &$errors): void
    {
        foreach (FloatingContactInput::errors($value, $pack->locales) as $problem) {
            $errors[] = "{$at}: {$problem}";
        }
    }

    /**
     * Slot → {locale: {"blocks": […]}}, checked as the admin checks a site's block slots
     * (LayoutSlotInput): a slot of LayoutSlots, a language the pack lists, the slot's block limit, the
     * editor's ValidPageJson for a slot, a block type the slot accepts. Block links must be links a
     * pack may write; an image reference is not resolved inside a slot, so it is refused.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $errors
     */
    private function layoutBlocks(string $at, array $value, Pack $pack, array &$errors): void
    {
        foreach ($value as $slot => $perLocale) {
            $slot = (string) $slot;
            if (! LayoutSlots::has($slot)) {
                $errors[] = "{$at}: '{$slot}' is not a block slot (".implode(', ', LayoutSlots::SLOTS).').';

                continue;
            }
            if (! is_array($perLocale) || $perLocale === [] || array_is_list($perLocale)) {
                $errors[] = "{$at}: '{$slot}' must be {language: {\"blocks\": […]}}.";

                continue;
            }
            foreach ($perLocale as $locale => $text) {
                $where = "{$at}: '{$slot}' ({$locale})";
                $blocks = is_array($text) ? ($text['blocks'] ?? null) : null;
                if (! in_array((string) $locale, $pack->locales, true)) {
                    $errors[] = "{$where}: the pack does not list this language.";
                } elseif (! is_array($blocks) || ! array_is_list($blocks)) {
                    $errors[] = "{$where}: needs {\"blocks\": […]}.";
                } elseif (($max = LayoutSlots::maxBlocks($slot)) !== null && count($blocks) > $max) {
                    $errors[] = "{$where}: holds at most {$max} blocks.";
                } elseif (self::slotsIn($blocks) !== [] || self::documentsIn($blocks) !== []) {
                    $errors[] = "{$where}: an image reference is not supported inside a block slot.";
                } else {
                    $check = Validator::make(['blocks' => $blocks], ['blocks' => [$this->pageJson->forSlots()]]);
                    foreach ($check->errors()->all() as $message) {
                        $errors[] = "{$where}: {$message}";
                    }
                    foreach ($blocks as $index => $block) {
                        $type = is_array($block) ? ($block['type'] ?? null) : null;
                        if (is_string($type) && ! LayoutSlots::accepts($slot, $type)) {
                            $errors[] = "{$where}: block {$type} does not belong in this slot.";
                        }
                        if (is_string($type) && $this->components->has($type) && is_array($block['props'] ?? null)) {
                            $this->blockLinks("{$where}: block ".($index + 1)." ({$type})", $block['props'], $this->components->schemaFor($type), '', $errors);
                            $schema = $this->components->schemaFor($type);
                            $files = ButtonLink::refs($block['props'], $schema)['files'];
                            $htmlImages = [];
                            foreach (SchemaHtml::values($block['props'], $schema) as $html) {
                                array_push($htmlImages, ...RichHtml::imageIds($html));
                            }
                            if (SchemaImages::ids($block['props'], $schema) !== [] || $files !== [] || $htmlImages !== []) {
                                $errors[] = "{$where}: block ".($index + 1)." ({$type}) names a library picture or file by id or key; a block slot of a pack cannot reference media.";
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param list<string> $errors */
    private function profile(Pack $pack, PackItem $item, array &$errors): void
    {
        $fields = $item->data['fields'] ?? [];
        if (! is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            $errors[] = 'profile: fields must be an object.';
            $fields = [];
        }
        foreach ($fields as $name => $value) {
            $name = (string) $name;
            if (! in_array($name, self::PROFILE_FIELDS, true)) {
                $errors[] = "profile: '{$name}' is not a profile field the loader writes.";

                continue;
            }
            $ok = match ($name) {
                'social_links' => is_array($value) && ($value === [] || ! array_is_list($value)) && Cast::strings($value) === array_values($value)
                    && array_all($value, self::isWebLink(...)),
                'moit_url' => $value === null || (self::isWebLink($value) && mb_strlen(Cast::string($value)) <= self::TEXT_MAX),
                'moit_registered' => is_bool($value),
                'license_issued_at' => $value === null || (is_string($value) && self::isDate($value)),
                default => $value === null || (is_string($value) && mb_strlen($value) <= self::TEXT_MAX),
            };
            if (! $ok) {
                $errors[] = "profile: '{$name}' has a value of the wrong shape.";
            }
        }

        $localized = $item->data['localized'] ?? [];
        if (! is_array($localized) || ($localized !== [] && array_is_list($localized))) {
            $errors[] = 'profile: localized must be an object.';
            $localized = [];
        }
        foreach (array_keys($localized) as $name) {
            $name = (string) $name;
            if (! in_array($name, self::PROFILE_LOCALIZED, true)) {
                $errors[] = "profile: '{$name}' is not a localized profile field the loader writes.";

                continue;
            }
            if ($localized[$name] === null) {
                $errors[] = "profile: {$name} must be a language map like {\"{$pack->defaultLocale}\": \"…\"}; leave the field out to keep the live text.";

                continue;
            }
            $this->localeMap($pack, 'profile', $localized, $name, false, $name === 'description' ? null : self::TEXT_MAX, $errors);
        }
    }

    /**
     * @param  array<string, true>  $earlier  taxonomy keys seen so far (parents come first)
     * @param  list<string>  $errors
     */
    private function taxonomy(Pack $pack, PackItem $item, array $earlier, array &$errors): void
    {
        $chain = self::taxonomyChain($item->key);
        if ($chain === null) {
            $errors[] = "{$item->key}: a category key is tax:<vocabulary>/<slug>/<slug>….";

            return;
        }
        if (count($chain['slugs']) > TaxonomyRules::MAX_DEPTH) {
            $errors[] = "{$item->key}: categories nest at most ".TaxonomyRules::MAX_DEPTH.' levels.';
        }

        $parent = $item->data['parent'] ?? null;
        if (count($chain['slugs']) === 1) {
            if ($parent !== null) {
                $errors[] = "{$item->key}: a top-level category has no parent.";
            }
        } else {
            $parentChain = is_string($parent) ? self::taxonomyChain($parent) : null;
            if ($parentChain === null || $parentChain['slugs'] !== array_slice($chain['slugs'], 0, -1)) {
                $errors[] = "{$item->key}: parent must be the category key of the chain above it.";
            } elseif (! isset($earlier[(string) $parent])) {
                $errors[] = "{$item->key}: its parent {$parent} must come earlier in taxonomies.json.";
            }
        }

        $this->localeMap($pack, $item->key, $item->data, 'name', true, TaxonomyRules::NAME_MAX, $errors);
        $this->slugMap($pack, $item, $errors);

        foreach (Cast::arr($item->data['was'] ?? []) as $was) {
            if (! is_string($was) || preg_match('/\A'.self::SLUG.'\z/', $was) !== 1) {
                $errors[] = "{$item->key}: was must list earlier slugs.";
            }
        }
        $position = $item->data['position'] ?? 0;
        if (! is_int($position) || $position < 0) {
            $errors[] = "{$item->key}: position must be a whole number from 0.";
        }
        foreach (['list_template', 'detail_template'] as $field) {
            $template = $item->data[$field] ?? null;
            if ($template === null || $template === TemplateCatalog::PARENT) {
                continue;
            }
            $postDetail = $field === 'detail_template' && $chain['vocabulary'] !== 'product_cat';
            if (! is_string($template) || ! TaxonomyRules::template($template)) {
                $errors[] = "{$item->key}: {$field} is not a layout name.";
            } elseif ($field === 'list_template' && ! TaxonomyRules::listTemplate($template, $chain['vocabulary'])) {
                $errors[] = "{$item->key}: list_template must be a list layout that fits this kind of category ({$template} does not), or @parent.";
            } elseif ($postDetail && ! TaxonomyRules::postDetailTemplate($template)) {
                // Only the post route reads it, and only post layouts render a post (the list layouts expect
                // a page of cards; the product layout expects a product). A product category's detail layout
                // is read nowhere today, so it keeps any theme layout (same as CreateTaxonomy/UpdateTaxonomy).
                $errors[] = "{$item->key}: detail_template must be a post layout, or @parent.";
            }
        }
    }

    /**
     * @return array{vocabulary: string, slugs: list<string>}|null
     */
    public static function taxonomyChain(string $key): ?array
    {
        if (preg_match('/\Atax:([a-z][a-z0-9_]{0,31})\/('.self::SLUG.'(?:\/'.self::SLUG.')*)\z/', $key, $m) !== 1) {
            return null;
        }

        return ['vocabulary' => $m[1], 'slugs' => explode('/', $m[2])];
    }

    /**
     * @param  array<string, true>  $taxonomies
     * @param  list<string>  $errors
     */
    private function product(Pack $pack, PackItem $item, array $taxonomies, array &$errors): void
    {
        if (preg_match('/\Aproduct:'.self::SLUG.'\z/', $item->key) !== 1) {
            $errors[] = "{$item->key}: a product key is product:<slug>.";
        }
        $this->flags($item, $errors);
        $this->categories($item, $taxonomies, false, $errors);
        $this->localeMap($pack, $item->key, $item->data, 'name', true, self::TEXT_MAX, $errors);
        $this->slugMap($pack, $item, $errors);
        foreach (['seo_title', 'seo_description'] as $field) {
            $this->localeMap($pack, $item->key, $item->data, $field, false, self::TEXT_MAX, $errors);
        }
        $this->localeMap($pack, $item->key, $item->data, 'summary', false, null, $errors);
        $this->localeMap($pack, $item->key, $item->data, 'description', false, null, $errors);
        foreach (Cast::arr($item->data['summary'] ?? []) as $locale => $text) {
            if (is_string($text) && $this->sanitizer->basic($text) !== $text) {
                $errors[] = "{$item->key}: summary ({$locale}) would be changed by the sanitizer.";
            }
        }
        foreach (Cast::arr($item->data['description'] ?? []) as $locale => $html) {
            if (is_string($html) && $this->sanitizer->full($html) !== $html) {
                $errors[] = "{$item->key}: description ({$locale}) would be changed by the sanitizer; use the markup it keeps.";
            }
        }
        $images = $item->data['images'] ?? [];
        if (! is_array($images) || ! array_is_list($images)) {
            $errors[] = "{$item->key}: images must be a list of image references.";
        } else {
            foreach ($images as $image) {
                if (! self::isMediaRef($image)) {
                    $errors[] = "{$item->key}: images must be a list of image references.";
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $taxonomies
     * @param  list<string>  $errors
     */
    private function post(Pack $pack, PackItem $item, array $taxonomies, array &$errors): void
    {
        if (preg_match('/\Apost:'.self::SLUG.'\z/', $item->key) !== 1) {
            $errors[] = "{$item->key}: a post key is post:<slug>.";
        }
        $this->flags($item, $errors);
        $this->categories($item, $taxonomies, true, $errors);
        $this->entryText($pack, $item, $errors);

        $image = $item->data['image'] ?? null;
        if ($image !== null && ! self::isMediaRef($image) && ! (is_string($image) && preg_match('/\A[a-z][a-z0-9-]{0,31}\z/', $image) === 1)) {
            $errors[] = "{$item->key}: image is an image reference or an icon name.";
        }
        $date = $item->data['published_at'] ?? null;
        if ($date !== null && ! (is_string($date) && self::isDate($date))) {
            $errors[] = "{$item->key}: published_at must be a date (YYYY-MM-DD).";
        }
    }

    /**
     * @param  array<string, true>  $earlier  page keys seen so far (a parent in the pack comes first)
     * @param  list<string>  $errors
     */
    private function page(Pack $pack, PackItem $item, array $earlier, array &$errors): void
    {
        if (preg_match('/\Apage:\/(?:'.self::SLUG.')?\z/', $item->key) !== 1) {
            $errors[] = "{$item->key}: a page key is page:/ or page:/<slug>.";
        }
        $this->pageParent($pack, $item, $earlier, $errors);
        $this->flags($item, $errors);
        $this->entryText($pack, $item, $errors);
    }

    /**
     * `parent` (optional) names the page this one sits under: the breadcrumb and the admin tree show
     * it, the address stays the page's own. Null puts the page back at the top. A parent in the pack
     * comes earlier in pages.json, so the pack itself cannot hold a loop; a parent outside the pack
     * must be a page on the site, which the plan checks.
     *
     * @param  array<string, true>  $earlier
     * @param  list<string>  $errors
     */
    private function pageParent(Pack $pack, PackItem $item, array $earlier, array &$errors): void
    {
        if (! array_key_exists('parent', $item->data) || $item->data['parent'] === null) {
            return;
        }
        $parent = $item->data['parent'];
        if (! is_string($parent) || preg_match('/\Apage:\/'.self::SLUG.'\z/', $parent) !== 1) {
            $errors[] = "{$item->key}: parent must be the key of another page (page:/<slug>), not the home page.";
        } elseif ($parent === $item->key) {
            $errors[] = "{$item->key}: a page cannot be its own parent.";
        } elseif ($pack->item($parent) !== null && ! isset($earlier[$parent])) {
            $errors[] = "{$item->key}: its parent {$parent} must come earlier in pages.json.";
        }
    }

    /** @param list<string> $errors */
    private function entryText(Pack $pack, PackItem $item, array &$errors): void
    {
        $this->localeMap($pack, $item->key, $item->data, 'title', true, self::TEXT_MAX, $errors);
        foreach (['excerpt', 'seo_title', 'seo_description'] as $field) {
            $this->localeMap($pack, $item->key, $item->data, $field, false, self::TEXT_MAX, $errors);
        }
        if ($item->kind === 'post') {
            $this->slugMap($pack, $item, $errors);
        }

        $body = $item->data['body'] ?? null;
        if (! is_array($body) || ! isset($body[$pack->defaultLocale])) {
            $errors[] = "{$item->key}: body needs the '{$pack->defaultLocale}' blocks.";

            return;
        }
        foreach ($body as $locale => $blocks) {
            if (! in_array($locale, $pack->locales, true)) {
                $errors[] = "{$item->key}: body has a language the pack does not list ({$locale}).";

                continue;
            }
            $check = Validator::make(['body' => self::withPlaceholderMedia($blocks)], ['body' => [$this->pageJson]]);
            foreach ($check->errors()->all() as $message) {
                $errors[] = "{$item->key}: body ({$locale}): {$message}";
            }
            foreach (is_array($blocks) ? array_values($blocks) : [] as $index => $block) {
                $type = is_array($block) ? ($block['type'] ?? null) : null;
                if (is_string($type) && $this->components->has($type) && is_array($block['props'] ?? null)) {
                    $this->blockLinks("{$item->key}: body ({$locale}): block ".($index + 1)." ({$type})", $block['props'], $this->components->schemaFor($type), '', $errors);
                }
            }
        }
    }

    /**
     * The schema's `url`-typed props (lists included): a contact `phone` must make a tel: link, an
     * `email` a mailto: link, and every other one must be a link a pack may write. The editor's own
     * rule only refuses dangerous schemes; a pack is held to the stricter one.
     *
     * @param  array<array-key, mixed>  $props
     * @param  list<string>  $errors
     */
    private function blockLinks(string $at, array $props, mixed $schema, string $prefix, array &$errors): void
    {
        foreach (is_array($schema) ? $schema : [] as $name => $spec) {
            $value = $props[$name] ?? null;
            if (! is_array($spec) || $value === null) {
                continue;
            }
            $type = $spec['type'] ?? null;
            if ($type === 'url') {
                $problem = match ($name) {
                    'phone' => SafeLink::tel($value) === null ? 'must be a phone number' : null,
                    'email' => SafeLink::mailto($value) === null ? 'must be an email address' : null,
                    default => self::isLink($value) ? null : 'must be '.self::LINK_RULE,
                };
                if ($problem !== null) {
                    $errors[] = "{$at}: {$prefix}{$name} {$problem}.";
                }
            } elseif ($type === 'list' && is_array($value) && is_array($spec['of'] ?? null) && ! is_string($spec['of']['type'] ?? null)) {
                foreach (array_values($value) as $n => $row) {
                    if (is_array($row)) {
                        $this->blockLinks($at, $row, $spec['of'], "{$prefix}{$name} ".($n + 1).' ', $errors);
                    }
                }
            }
        }
    }

    /**
     * Image references stand in as a media id while the body is checked (they resolve later); a file
     * button stands in with no file chosen yet and its label as the plain text.
     */
    private static function withPlaceholderMedia(mixed $value): mixed
    {
        if (self::isMediaRef($value)) {
            return 1;
        }
        if (self::isFileButton($value)) {
            return ['k' => 'file'];
        }
        if (self::isFileLabel($value)) {
            return $value['text'];
        }

        return is_array($value) ? array_map(self::withPlaceholderMedia(...), $value) : $value;
    }

    /** @param list<string> $errors */
    private function flags(PackItem $item, array &$errors): void
    {
        $verified = $item->data['verified'] ?? null;
        $status = $item->data['status'] ?? null;
        $claims = $item->data['claims'] ?? [];

        if (! is_bool($verified)) {
            $errors[] = "{$item->key}: verified must be true or false.";
        }
        if (! in_array($status, self::STATUSES, true)) {
            $errors[] = "{$item->key}: status must be published or draft.";
        }
        if (! is_array($claims) || ! array_is_list($claims) || Cast::strings($claims) !== $claims) {
            $errors[] = "{$item->key}: claims must be a list of quoted sentences.";
        }
        if ($verified === false && $status === 'published') {
            $errors[] = "{$item->key}: an unverified item cannot be published (verified:false needs status draft).";
        }
        if ($verified === false && $claims === []) {
            $errors[] = "{$item->key}: an unverified item lists its claims for the confirmation sheet.";
        }
    }

    /**
     * @param  array<string, true>  $taxonomies
     * @param  list<string>  $errors
     */
    private function categories(PackItem $item, array $taxonomies, bool $required, array &$errors): void
    {
        $categories = $item->data['categories'] ?? [];
        if (! is_array($categories) || ! array_is_list($categories) || Cast::strings($categories) !== $categories) {
            $errors[] = "{$item->key}: categories must be a list of category keys.";

            return;
        }
        if ($required && $categories === []) {
            $errors[] = "{$item->key}: a post needs at least one category (the first is its primary).";
        }
        if (count(array_unique($categories)) !== count($categories)) {
            $errors[] = "{$item->key}: a category is listed twice.";
        }
        foreach ($categories as $key) {
            if (! isset($taxonomies[$key])) {
                $errors[] = "{$item->key}: category {$key} is not in taxonomies.json.";
            }
        }
    }

    /** @param list<string> $errors */
    private function menu(Pack $pack, PackItem $item, array &$errors): void
    {
        if (preg_match('/\Amenu:[a-z][a-z0-9_-]{0,31}\z/', $item->key) !== 1) {
            $errors[] = "{$item->key}: a menu key is menu:<region>.";
        }
        $name = $item->data['name'] ?? null;
        if (! is_string($name) || $name === '' || mb_strlen($name) > self::TEXT_MAX) {
            $errors[] = "{$item->key}: name is the menu's admin name.";
        }
        $this->menuNodes($pack, $item->key, $item->data['items'] ?? null, 1, $errors);
    }

    /** @param list<string> $errors */
    private function menuNodes(Pack $pack, string $at, mixed $nodes, int $depth, array &$errors): void
    {
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            $errors[] = "{$at}: items must be a list.";

            return;
        }
        if ($depth > self::MENU_DEPTH && $nodes !== []) {
            $errors[] = "{$at}: menus nest at most ".self::MENU_DEPTH.' levels.';

            return;
        }
        foreach ($nodes as $index => $node) {
            $where = "{$at} item ".($depth > 1 ? "{$depth}." : '').($index + 1);
            $node = Cast::arr($node);
            $this->localeMap($pack, $where, $node, 'label', true, self::TEXT_MAX, $errors);
            $links = array_intersect(['target', 'url', 'dynamic'], array_keys($node));
            if (count($links) !== 1) {
                $errors[] = "{$where}: give exactly one of target, url, dynamic.";
            } elseif (isset($node['target']) && ! (is_string($node['target']) && self::isTargetKey($node['target']))) {
                $errors[] = "{$where}: target must be a page, post, category or product key.";
            } elseif (isset($node['url']) && ! (is_string($node['url']) && ($node['url'] === '#' || self::isLink($node['url'])))) {
                $errors[] = "{$where}: url is not a safe link.";
            } elseif (isset($node['dynamic']) && ! in_array($node['dynamic'], self::DYNAMIC_LINKS, true)) {
                $errors[] = "{$where}: dynamic must be one of ".implode(', ', self::DYNAMIC_LINKS).'.';
            }
            if (isset($node['children'])) {
                $this->menuNodes($pack, $where, $node['children'], $depth + 1, $errors);
            }
        }
    }

    public static function isTargetKey(string $key): bool
    {
        return preg_match('/\A(?:page:\/(?:'.self::SLUG.')?|(?:post|product):'.self::SLUG.')\z/', $key) === 1
            || self::taxonomyChain($key) !== null;
    }

    /** @param list<string> $errors */
    private function redirect(Pack $pack, PackItem $item, array &$errors): void
    {
        if (preg_match('/\Aredirect:([a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?):(.*)\z/s', $item->key, $m) !== 1 || ! SafeLink::isSitePath($m[2])) {
            $errors[] = "{$item->key}: a redirect key is redirect:<locale>:/<path>.";

            return;
        }
        if (! in_array($m[1], $pack->locales, true)) {
            $errors[] = "{$item->key}: the pack does not list language '{$m[1]}'.";
        }
        $to = $item->data['to'] ?? null;
        if (! SafeLink::isSitePath($to) || $to === $m[2] || mb_strlen(Cast::string($to)) > self::TEXT_MAX) {
            $errors[] = "{$item->key}: to must be another path on this site (/…, never //host, a backslash, a space or a full link).";
        }
        if (! in_array($item->data['status'] ?? 301, [301, 302], true)) {
            $errors[] = "{$item->key}: status must be 301 or 302.";
        }
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  list<string>  $errors
     */
    private function slot(Pack $pack, string $name, array $slot, array &$errors): void
    {
        $at = "media slot '{$name}'";
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $name) !== 1) {
            $errors[] = "{$at}: a slot name is lowercase letters, digits and hyphens.";
        }
        $collection = $slot['collection'] ?? 'library';
        if (! is_string($collection) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $collection) !== 1) {
            $errors[] = "{$at}: collection is not a collection name.";
        }
        $legacy = $slot['legacy_key'] ?? null;
        if ($legacy !== null && (! is_string($legacy) || str_contains($legacy, '://') || ! str_contains($legacy, '/'))) {
            $errors[] = "{$at}: legacy_key must be a storage key.";
        }
        if (isset($slot['alt'])) {
            $this->localeMap($pack, $at, $slot, 'alt', false, Media::ALT_MAX_LENGTH, $errors);
        }
        if (! in_array($slot['kind'] ?? 'image', ['image', 'document'], true)) {
            $errors[] = "{$at}: kind is image or document.";

            return;
        }

        $file = $slot['file'] ?? null;
        if ($file === null) {
            return;
        }
        if (! is_string($file) || str_contains($file, '..') || str_starts_with($file, '/')) {
            $errors[] = "{$at}: file must be a path inside the pack.";

            return;
        }
        $path = $pack->dir.'/'.$file;
        $document = self::isDocument($slot);
        if (self::mime($path, $document) === null) {
            $errors[] = $document
                ? "{$at}: {$file} is missing or not a PDF document."
                : "{$at}: {$file} is missing or not a JPEG/PNG/WebP image.";

            return;
        }
        // The media library's own upload limit: a larger file would pass here and fail at upload.
        $max = UploadMedia::MAX_KB * 1024;
        if ((int) filesize($path) > $max) {
            $errors[] = sprintf('%s: %s is %.1f MB; the media library takes files up to %d MB.', $at, $file, (int) filesize($path) / 1048576, $max / 1048576);
        }
    }

    /**
     * Whether a media.json slot holds a document (`"kind": "document"`, a PDF) rather than an image.
     *
     * @param  array<array-key, mixed>  $slot
     */
    public static function isDocument(array $slot): bool
    {
        return ($slot['kind'] ?? null) === 'document';
    }

    /**
     * The type a file really is, by its first bytes; null when it is none of the allowed ones. A
     * document slot allows only a PDF, an image slot only a JPEG, PNG or WebP image.
     */
    public static function mime(string $path, bool $document = false): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $head = (string) file_get_contents($path, false, null, 0, 16);
        if ($document) {
            return str_starts_with($head, '%PDF-') ? 'application/pdf' : null;
        }
        foreach (self::MAGIC as $magic => $mime) {
            if (str_starts_with($head, $magic)) {
                return $mime;
            }
        }

        return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' ? 'image/webp' : null;
    }

    /**
     * A locale map field: an object of language → text, only the pack's languages, the default one
     * present when required, within $max characters.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $errors
     */
    private function localeMap(Pack $pack, string $at, array $data, string $field, bool $required, ?int $max, array &$errors): void
    {
        if (! array_key_exists($field, $data) || $data[$field] === null) {
            if ($required) {
                $errors[] = "{$at}: {$field} is required ({\"{$pack->defaultLocale}\": \"…\"}).";
            }

            return;
        }
        $map = $data[$field];
        if (! is_array($map) || array_is_list($map)) {
            $errors[] = "{$at}: {$field} must be a language map like {\"{$pack->defaultLocale}\": \"…\"}.";

            return;
        }
        if ($required && ! is_string($map[$pack->defaultLocale] ?? null)) {
            $errors[] = "{$at}: {$field} needs the '{$pack->defaultLocale}' text.";
        }
        foreach ($map as $locale => $text) {
            if (! in_array($locale, $pack->locales, true)) {
                $errors[] = "{$at}: {$field} has a language the pack does not list ({$locale}).";
            } elseif (! is_string($text) || ($required && trim($text) === '')) {
                $errors[] = "{$at}: {$field} ({$locale}) must be text.";
            } elseif ($max !== null && mb_strlen($text) > $max) {
                $errors[] = "{$at}: {$field} ({$locale}) is longer than {$max} characters.";
            }
        }
    }

    /** @param list<string> $errors */
    private function slugMap(Pack $pack, PackItem $item, array &$errors): void
    {
        $map = $item->data['slug'] ?? null;
        if ($map === null) {
            return;
        }
        if (! is_array($map) || array_is_list($map)) {
            $errors[] = "{$item->key}: slug must be a language map (other languages only; the key holds the default slug).";

            return;
        }
        foreach ($map as $locale => $slug) {
            if ($locale === $pack->defaultLocale || ! in_array($locale, $pack->locales, true)) {
                $errors[] = "{$item->key}: slug may only name the pack's other languages ({$locale}).";
            } elseif (! is_string($slug) || preg_match('/\A'.self::SLUG.'\z/', $slug) !== 1) {
                $errors[] = "{$item->key}: slug ({$locale}) is not a slug.";
            }
        }
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
