<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Theme\Components\ListDisplay;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Rendering\BlockRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

/*
 * The about block as a letter (greeting, signature, shortcut tiles, photo focus), the Zalo cell of the
 * contact block and the zigzag look of the article list.
 */

/** @param  array<string, mixed>  $props */
function aboutHtml(array $props): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.about', ['props' => $props])->render();
}

/** @param  array<string, mixed>  $props */
function contactHtml(array $props): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.contact', ['props' => $props])->render();
}

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function aboutErrors(array $props, string $type, string $locale = 'vi'): array
{
    app()->setLocale($locale);

    return array_values(Validator::make(['blocks' => [['type' => $type, 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

describe('about letter', function (): void {
    it('prints the first paragraph in bold and the last two as the signature from three paragraphs up', function (): void {
        $html = aboutHtml(['variant' => 'letter', 'heading' => 'Ấ thư ngỏ Ế', 'paragraphs' => ['Ấn kính gửi Ế', 'Ộ nội dung một Ế', 'Ộ nội dung hai Ế', 'Ấ trân trọng Ợ', 'Ấ Ban Lãnh đạo Ấ', 'Ế Công ty An Vinh Ế']]);

        expect($html)->toContain('about about--letter')
            ->toContain('<p class="about__paragraph about__paragraph--greeting">Ấn kính gửi Ế</p>')
            ->toContain('<p class="about__paragraph">Ộ nội dung một Ế</p>')
            ->toContain('<p class="about__paragraph">Ấ trân trọng Ợ</p>')
            ->toContain('<div class="about__signature"><p class="about__signer">Ấ Ban Lãnh đạo Ấ</p><p class="about__signer-role">Ế Công ty An Vinh Ế</p></div>')
            ->and(substr_count($html, 'about__paragraph--greeting'))->toBe(1)
            ->and($html)->not->toContain('<p class="about__paragraph">Ấ Ban Lãnh đạo Ấ</p>');
    });

    it('builds the signature only from three paragraphs', function (int $count, bool $signed): void {
        $paragraphs = array_map(fn (int $i): string => "Ấn đoạn {$i} Ế", range(1, $count));
        $html = aboutHtml(['variant' => 'letter', 'paragraphs' => $paragraphs]);

        expect(str_contains($html, 'about__signature'))->toBe($signed)
            ->and(substr_count($html, 'about__paragraph--greeting'))->toBe(1);

        // With a signature the two last paragraphs leave the body; without one every paragraph stays in it.
        expect(substr_count($html, '<p class="about__paragraph'))->toBe($signed ? $count - 2 : $count);
    })->with([[1, false], [2, false], [3, true], [5, true]]);

    it('prints every saved text as text, never as markup', function (): void {
        $html = aboutHtml(['variant' => 'letter', 'heading' => '<b>H</b>', 'paragraphs' => ['<script>alert(1)</script>', 'Ữ giữa Ế', '<img src=x onerror=1>', '<script>alert(2)</script>']]);

        expect($html)->not->toContain('<script>')->not->toContain('<img src=x')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->toContain('&lt;script&gt;alert(2)&lt;/script&gt;');
    });

    it('leaves the default look without a greeting or a signature', function (): void {
        $html = aboutHtml(['variant' => 'default', 'paragraphs' => ['A', 'B', 'C']]);

        expect($html)->not->toContain('about--letter')->not->toContain('greeting')->not->toContain('about__signature')
            ->and(aboutHtml(['variant' => 'bogus', 'paragraphs' => ['A', 'B', 'C']]))->not->toContain('about--letter');
    });
});

describe('about links', function (): void {
    it('draws up to four tiles through the same typed button path as the quick links', function (): void {
        $links = array_map(fn (int $i): array => ['icon' => 'phone', 'label' => "Ấn mục {$i} Ế", 'url' => "/trang-{$i}"], range(1, 6));
        $html = aboutHtml(['paragraphs' => ['A'], 'links' => $links]);

        expect(substr_count($html, '<a class="about__link'))->toBe(4)
            ->and($html)->toContain('about__link about__link--primary')->toContain('href="/trang-1"')->toContain('Ấn mục 4 Ế')
            ->not->toContain('Ấn mục 5');
    });

    it('skips a tile without a label or with an unsafe address, and falls back on an unknown icon', function (): void {
        $html = aboutHtml(['links' => [
            ['icon' => 'phone', 'label' => '', 'url' => '/a'],
            ['icon' => 'phone', 'label' => 'Ấ xấu Ế', 'url' => 'javascript:alert(1)'],
            ['icon' => 'rocket', 'label' => 'Ố tốt Ế', 'url' => '/b'],
        ]]);

        expect(substr_count($html, '<a class="about__link'))->toBe(1)->and($html)->toContain('Ố tốt Ế')->not->toContain('javascript');
    });

    it('opens an external typed address in a new tab with a hidden note', function (): void {
        $html = aboutHtml(['links' => [['icon' => 'link', 'label' => 'Ấ ngoài Ế', 'url' => '', 'url_do' => ['k' => 'url', 'url' => 'https://example.com/x', 'blank' => true]]]]);

        expect($html)->toContain('target="_blank" rel="noopener"')->toContain('sr-only');
    });

    it('refuses more than four tiles and an unknown icon on save', function (): void {
        $tile = fn (string $icon = 'phone'): array => ['icon' => $icon, 'label' => 'A', 'url' => '/a'];

        expect(aboutErrors(['links' => array_fill(0, 4, $tile())], 'about'))->toBe([])
            ->and(aboutErrors(['links' => array_fill(0, 5, $tile())], 'about'))->not->toBe([])
            ->and(aboutErrors(['links' => [$tile('rocket')]], 'about'))->not->toBe([])
            ->and(aboutErrors(['links' => [['icon' => 'phone', 'label' => 'A', 'url' => 'javascript:alert(1)']]], 'about'))->not->toBe([]);
    });
});

describe('about image focus', function (): void {
    it('maps the focus to a fixed class and falls back to the centre for anything else', function (): void {
        $base = ['image_url' => 'https://f/a.webp', 'heading' => 'H'];

        expect(aboutHtml($base + ['image_focus' => 'top']))->toContain('class="about__image about__image--top"')
            ->and(aboutHtml($base + ['image_focus' => 'bottom']))->toContain('class="about__image about__image--bottom"')
            ->and(aboutHtml($base + ['image_focus' => 'center']))->toContain('class="about__image"')
            ->and(aboutHtml($base))->toContain('class="about__image"');
    });

    it('never puts a saved string into the class', function (): void {
        $html = aboutHtml(['image_url' => 'https://f/a.webp', 'image_focus' => 'top" onload="alert(1)']);

        expect($html)->toContain('class="about__image"')->not->toContain('onload')->not->toContain('image--top');
    });

    it('lists the choices append-only with a label in every language', function (): void {
        foreach (['vi', 'en', 'ja', 'zh'] as $locale) {
            $lang = json_decode((string) file_get_contents(lang_path($locale.'.json')), true);
            foreach (SectionProps::ABOUT_IMAGE_FOCUS as $option) {
                expect($lang)->toHaveKey('entries.opt_image_focus_'.$option);
            }
            foreach (['letter', 'zigzag'] as $option) {
                expect($lang)->toHaveKey('entries.opt_variant_'.$option);
            }
            expect($lang)->toHaveKey('entries.err_block_zalo_invalid');
        }
        expect(SectionProps::ABOUT_IMAGE_FOCUS)->toBe(['center', 'top', 'bottom'])
            ->and(SectionProps::ABOUT_VARIANTS)->toBe(['default', 'letter'])
            ->and(array_slice(SectionProps::ENTRY_GRID_VARIANTS, 0, 4))->toBe(['list', 'icon-cards', 'project-cards', 'news-cards']);
    });
});

describe('contact Zalo', function (): void {
    it('accepts a phone number and a zalo.me link on save', function (string $zalo): void {
        expect(aboutErrors(['zalo' => $zalo], 'contact'))->toBe([]);
    })->with(['0915668383', '0915 668 383', 'https://zalo.me/0915668383', 'zalo.me/0915668383']);

    it('refuses any other link with a plain message in the editor language', function (string $zalo): void {
        $errors = aboutErrors(['zalo' => $zalo], 'contact');

        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('Zalo')->not->toContain('entries.');
        expect(aboutErrors(['zalo' => $zalo], 'contact', 'en')[0])->toContain('Zalo link');
    })->with(['https://example.com/zalo', 'https://zalo.me.evil.test/0915668383', 'abc', '12345']);

    it('draws a Zalo cell titled Zalo with the label under it, and none when empty', function (): void {
        $props = ['variant' => 'cards', 'phone' => '0915668383', 'email' => 'a@b.vn'];
        $html = contactHtml($props + ['zalo' => '0915668383', 'zalo_label' => 'Gửi ảnh bản vẽ qua Zalo Ấ']);

        expect($html)->toContain('<h3 class="sf-contact-card__title">Zalo</h3>')
            ->toContain('href="https://zalo.me/0915668383"')->toContain('Gửi ảnh bản vẽ qua Zalo Ấ')
            ->and(substr_count($html, 'class="sf-contact-card"'))->toBe(3);

        foreach ([[], ['zalo' => ''], ['zalo' => '  '], ['zalo' => 'https://evil.test/x'], ['zalo_label' => 'Ỉ nhãn Ế']] as $extra) {
            expect(contactHtml($props + $extra))->not->toContain('Zalo')->not->toContain('zalo.me');
        }
    });

    it('draws a Zalo link in the plain list look and escapes the label', function (): void {
        $html = contactHtml(['zalo' => 'https://zalo.me/0915668383', 'zalo_label' => '<i>Zalo</i>']);

        expect($html)->toContain('class="contact__zalo" href="https://zalo.me/0915668383"')->toContain('&lt;i&gt;Zalo&lt;/i&gt;')->not->toContain('<i>');
    });
});

describe('zigzag list', function (): void {
    /** @param  list<array<string, string>>  $cards */
    function zigzagHtml(array $cards, array $props = []): string
    {
        app()->setLocale('vi');

        return View::make('theme::sections.entry_grid', ['props' => $props, 'items' => $cards, 'variant' => 'zigzag', 'display' => null])->render();
    }

    function zigzagCards(int $count): array
    {
        return array_map(fn (int $i): array => ['title' => "Ấn năng lực {$i} Ế", 'url' => "/nl-{$i}", 'image' => "https://f/{$i}.webp", 'srcset' => null, 'icon' => '', 'excerpt' => "Ấ mô tả {$i} Ấ", 'date' => '', 'datetime' => '', 'label' => ''], range(1, $count));
    }

    it('alternates the photo side and numbers the rows from their order', function (): void {
        $html = zigzagHtml(zigzagCards(4), ['link_label' => 'Xem chi tiết năng lực']);

        expect(substr_count($html, 'sf-zigzag__row--flip'))->toBe(2)
            ->and($html)->toMatch('~<li id="nl-1" class="sf-zigzag__row">.*?>01</span>.*?<li id="nl-2" class="sf-zigzag__row sf-zigzag__row--flip">.*?>02</span>.*?<li id="nl-3" class="sf-zigzag__row">.*?>03</span>.*?sf-zigzag__row--flip">.*?>04</span>~s')
            ->and($html)->toContain('Xem chi tiết năng lực')->toContain('href="/nl-1"')->toContain('<h3 class="sf-zigzag__title">Ấn năng lực 2 Ế</h3>');
    });

    it('uses the default button text and shows no button without a link', function (): void {
        $cards = zigzagCards(1);
        $cards[0]['url'] = '';

        expect(zigzagHtml(zigzagCards(1)))->toContain('sf-zigzag__more')
            ->and(zigzagHtml($cards))->not->toContain('sf-zigzag__more');
    });

    it('shows the empty message with no items', function (): void {
        expect(zigzagHtml([]))->toContain('sf-empty')->not->toContain('sf-zigzag__list');
    });

    it('reads the same number of queries as a grid look', function (): void {
        DB::connection('pgsql_admin')->statement(
            'truncate tenant_probes, routes, entry_translations, entries,
             site_profile_translations, site_profiles, site_settings, site_locales,
             site_modules, content_sections, domains, sites, tenants, tenant_users, users
             restart identity cascade'
        );
        truncateRbac();
        app(ProvisionSite::class)->handle(new ProvisionSiteData(
            tenantName: 'Zig Co', siteName: 'Zig Co', hostname: 'zig.platform.test',
            ownerEmail: 'owner@zig.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
        ));
        $site = Site::query()->firstWhere('name', 'Zig Co');

        app(Tenancy::class)->enter($site->id, function (): void {
            $owner = User::query()->firstWhere('email', 'owner@zig.test');
            $cat = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
            $cat->translations()->create(['locale' => 'vi', 'name' => 'Năng lực', 'slug' => 'nang-luc']);
            foreach ([1, 2, 3] as $i) {
                $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id, 'meta' => ['image_url' => "https://cdn.example/{$i}.avif"]]);
                $entry->translations()->create(['locale' => 'vi', 'title' => "Ấ bài {$i} Ế", 'slug' => "bai-{$i}", 'status' => 'draft', 'excerpt' => "Ấ tóm tắt {$i} Ế", 'body' => ['blocks' => []]]);
                $entry->assignCategories([$cat->id]);
                app(PublishEntry::class)->handle($entry->fresh(), 'vi', $owner->fresh());
            }

            $component = app(EntryGridComponent::class);
            $base = ['taxonomy_slug' => 'nang-luc', 'limit' => 6, 'locale' => 'vi'];
            $zigzag = null;
            $zigzagQueries = countQueries(function () use ($component, $base, &$zigzag): void {
                $zigzag = $component->data($base + ['variant' => 'zigzag']);
            });
            $gridQueries = countQueries(fn () => $component->data($base + ['variant' => 'icon-cards']));

            expect($zigzag['items'])->toHaveCount(3)->and($zigzag['variant'])->toBe('zigzag')
                ->and($zigzagQueries)->toBe($gridQueries);

            $html = View::make('theme::sections.entry_grid', $zigzag)->render();
            expect(substr_count($html, 'sf-zigzag__row--flip'))->toBe(1)->and($html)->toContain('>03</span>');
        });
    });
});

describe('zigzag on a dark band and anchors', function (): void {
    it('puts a dark band behind the rows and colours the text for it', function (): void {
        $html = zigzagHtml(zigzagCards(2), ['bg' => 'dark']);
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        expect($html)->toContain('sf-band sf-band--dark sf-zigzag');

        // The text follows the block's colour set when it has one (the dark set), and a dark band without
        // a set repaints it for the dark ground, the way the section head does.
        preg_match('~\.sf-zigzag__title \{[^}]*\}~', $css, $title);
        preg_match('~\.sf-zigzag__excerpt \{[^}]*\}~', $css, $excerpt);
        expect($title[0] ?? '')->toContain('var(--sch-head, var(--color-text-strong))')
            ->and($excerpt[0] ?? '')->toContain('var(--sch-body, var(--color-text))')
            ->and($css)->toContain('.sf-band--dark .sf-zigzag__title {')
            ->and($css)->toContain('.sf-band--dark .sf-zigzag__excerpt {');
    });

    it('keeps the text on the block colour roles in the dark colour set', function (): void {
        $html = zigzagHtml(zigzagCards(1), []);
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        // The set rewrites the roles on the wrapper; the rows never name a colour of their own.
        expect($html)->not->toContain('style=')
            ->and($css)->toContain('.sf-s--sch-dark {')
            ->and($css)->not->toMatch('~\.sf-zigzag__[a-z-]+ \{[^}]*#fbbf24~i');
    });

    it('gives each row the slug of its page as an anchor and never a numeric id', function (): void {
        $cards = zigzagCards(3);
        $cards[0]['url'] = '/nang-luc/han-tig';
        $cards[1]['url'] = '/thiet-ke';
        $cards[2]['url'] = '';
        $html = zigzagHtml($cards);

        expect($html)->toContain('<li id="han-tig" class="sf-zigzag__row">')
            ->toContain('<li id="thiet-ke" class="sf-zigzag__row sf-zigzag__row--flip">')
            ->and(substr_count($html, ' id="'))->toBe(2);
    });

    it('numbers a repeated slug so every anchor on the page is unique', function (): void {
        $cards = zigzagCards(3);
        foreach ($cards as $i => $card) {
            $cards[$i]['url'] = '/mục-'.$i.'/han';
        }
        $html = zigzagHtml($cards);

        expect($html)->toContain('id="han"')->toContain('id="han-2"')->toContain('id="han-3"')
            ->and(preg_match_all('~<li id="([^"]+)"~', $html, $ids))->toBe(3)
            ->and(count(array_unique($ids[1])))->toBe(3);
    });

    it('prints no anchor for a row without a page address and escapes a hostile one', function (): void {
        $cards = zigzagCards(2);
        $cards[0]['url'] = '';
        $cards[1]['url'] = '/x/"><script>alert(1)</script>';
        $html = zigzagHtml($cards);

        expect($html)->toContain('<li class="sf-zigzag__row">')->not->toContain('<script>')->not->toContain('"><');
    });

    it('escapes a hostile title and excerpt', function (): void {
        $cards = zigzagCards(1);
        $cards[0]['title'] = '<script>alert(1)</script> Ấ';
        $cards[0]['excerpt'] = '<img src=x onerror=1> Ế';
        $html = zigzagHtml($cards);

        expect($html)->not->toContain('<script>')->not->toContain('<img src=x')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Ấ')->toContain('&lt;img src=x onerror=1&gt; Ế');
    });
});

describe('zigzag view all', function (): void {
    it('follows the show, hide and auto choices of the display group', function (): void {
        $view = fn (array $display): string => View::make('theme::sections.entry_grid', [
            'props' => ['view_all_label' => 'Xem tất cả Ấ', 'view_all_url' => '/nang-luc'],
            'items' => zigzagCards(2), 'variant' => 'zigzag', 'display' => $display,
        ])->render();

        expect($view(['view_all' => true]))->toContain('Xem tất cả Ấ')
            ->and($view(['view_all' => false]))->not->toContain('Xem tất cả Ấ')
            ->and(zigzagHtml(zigzagCards(2), ['view_all_label' => 'Xem tất cả Ấ', 'view_all_url' => '/nang-luc']))->toContain('Xem tất cả Ấ');
    });

    it('reads the one extra row only when the block carries the display group', function (): void {
        $plan = fn (array $props, int $found): ?array => ListDisplay::plan('news', ['layout' => 'grid'] + $props, $found, 3);

        expect($plan(['view_all' => 'auto'], 4)['view_all'])->toBeTrue()
            ->and($plan(['view_all' => 'auto'], 3)['view_all'])->toBeFalse()
            ->and($plan(['view_all' => 'hide'], 4)['view_all'])->toBeFalse()
            ->and($plan(['view_all' => 'show'], 2)['view_all'])->toBeTrue();
    });

    it('decides view all from the data of a zigzag block', function (): void {
        DB::connection('pgsql_admin')->statement(
            'truncate tenant_probes, routes, entry_translations, entries,
             site_profile_translations, site_profiles, site_settings, site_locales,
             site_modules, content_sections, domains, sites, tenants, tenant_users, users
             restart identity cascade'
        );
        truncateRbac();
        app(ProvisionSite::class)->handle(new ProvisionSiteData(
            tenantName: 'Zig Va', siteName: 'Zig Va', hostname: 'zigva.platform.test',
            ownerEmail: 'owner@zigva.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
        ));
        $site = Site::query()->firstWhere('name', 'Zig Va');

        app(Tenancy::class)->enter($site->id, function (): void {
            $owner = User::query()->firstWhere('email', 'owner@zigva.test');
            $cat = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
            $cat->translations()->create(['locale' => 'vi', 'name' => 'Năng lực', 'slug' => 'nang-luc']);
            foreach ([1, 2, 3] as $i) {
                $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id, 'meta' => []]);
                $entry->translations()->create(['locale' => 'vi', 'title' => "Ấ bài {$i} Ế", 'slug' => "bai-{$i}", 'status' => 'draft', 'excerpt' => "Ấ tóm tắt {$i} Ế", 'body' => ['blocks' => []]]);
                $entry->assignCategories([$cat->id]);
                app(PublishEntry::class)->handle($entry->fresh(), 'vi', $owner->fresh());
            }

            $component = app(EntryGridComponent::class);
            $base = ['taxonomy_slug' => 'nang-luc', 'locale' => 'vi', 'variant' => 'zigzag'];

            // Without the group the block keeps its old reading: no extra row, no plan, the button shows.
            $old = $component->data($base + ['limit' => 2]);
            expect($old['display'])->toBeNull()->and($old['items'])->toHaveCount(2);

            $auto = $component->data($base + ['limit' => 2, 'layout' => 'auto', 'view_all' => 'auto']);
            $all = $component->data($base + ['limit' => 3, 'layout' => 'auto', 'view_all' => 'auto']);
            $hide = $component->data($base + ['limit' => 2, 'layout' => 'auto', 'view_all' => 'hide']);
            $slider = $component->data($base + ['limit' => 2, 'layout' => 'slider', 'view_all' => 'auto']);

            expect($auto['items'])->toHaveCount(2)->and($auto['display']['view_all'])->toBeTrue()
                ->and($all['items'])->toHaveCount(3)->and($all['display']['view_all'])->toBeFalse()
                ->and($hide['display']['view_all'])->toBeFalse()
                ->and($slider['items'])->toHaveCount(2);
        });
    });
});

describe('saved values', function (): void {
    it('refuses a Zalo address that is a script and one that is not a number', function (): void {
        expect(aboutErrors(['zalo' => 'javascript:alert(1)'], 'contact'))->not->toBe([])
            ->and(aboutErrors(['zalo' => 'https://zalo.me/abc'], 'contact'))->not->toBe([])
            ->and(aboutErrors(['zalo' => 'https://zalo.me/0915668383'], 'contact'))->toBe([]);
    });

    it('refuses an unknown variant or photo focus on save', function (): void {
        expect(aboutErrors(['variant' => 'letter', 'image_focus' => 'top'], 'about'))->toBe([])
            ->and(aboutErrors(['variant' => 'bogus'], 'about'))->not->toBe([])
            ->and(aboutErrors(['image_focus' => 'left'], 'about'))->not->toBe([])
            ->and(aboutErrors(['variant' => 'zigzag'], 'entry_grid'))->toBe([])
            ->and(aboutErrors(['variant' => 'bogus'], 'entry_grid'))->not->toBe([]);
    });
});

describe('about letter with blank paragraphs', function (): void {
    it('leaves blank paragraphs out before it counts the three', function (): void {
        $three = aboutHtml(['variant' => 'letter', 'paragraphs' => ['Kính gửi Ấ', 'Nội dung Ế', 'Ấ Ban Lãnh đạo Ấ', 'Giám đốc Ế', '']]);
        $two = aboutHtml(['variant' => 'letter', 'paragraphs' => ['Kính gửi Ấ', 'Nội dung Ế', '', '   ']]);

        expect($three)->toContain('<div class="about__signature"><p class="about__signer">Ấ Ban Lãnh đạo Ấ</p><p class="about__signer-role">Giám đốc Ế</p></div>')
            ->and(substr_count($three, '<p class="about__paragraph'))->toBe(2)
            ->and($two)->not->toContain('about__signature')
            ->and(substr_count($two, '<p class="about__paragraph'))->toBe(2);
    });
});

describe('about links through the block renderer', function (): void {
    it('resolves a page action and a file action, with the same queries for one tile and four', function (): void {
        seedTenancy();

        app(Tenancy::class)->enter(1, function (): void {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
            $page->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $page->id]);
            Media::query()->create([
                'collection' => 'library', 'slug' => 'ho-so', 'hash' => hash('sha256', 'a/library/ho-so-aaaaaaaaaaaa.pdf'),
                'storage_key' => 'a/library/ho-so-aaaaaaaaaaaa.pdf', 'disk' => 'r2', 'mime' => 'application/pdf', 'size' => 2_400_000, 'original_filename' => 'Hồ sơ.pdf',
            ]);

            $tiles = [
                ['icon' => 'link', 'label' => 'Ấn liên hệ Ế', 'url_do' => ['k' => 'page', 'page' => $page->ulid]],
                ['icon' => 'document', 'label' => 'Ấn hồ sơ Ế', 'url_do' => ['k' => 'file', 'file' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']],
                ['icon' => 'link', 'label' => 'Ấn liên hệ hai Ế', 'url_do' => ['k' => 'page', 'page' => $page->ulid]],
                ['icon' => 'document', 'label' => 'Ấn hồ sơ hai Ế', 'url_do' => ['k' => 'file', 'file' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']],
            ];
            $render = static function (array $links): array {
                app()->setLocale('vi');
                DB::flushQueryLog();
                DB::enableQueryLog();
                $html = app(BlockRenderer::class)->render(['blocks' => [['type' => 'about', 'props' => ['paragraphs' => ['A'], 'links' => $links]]]]);
                $n = count(DB::getQueryLog());
                DB::disableQueryLog();

                return [$html, $n];
            };

            [$one, $oneQueries] = $render(array_slice($tiles, 0, 1));
            [$four, $fourQueries] = $render($tiles);

            expect($one)->toContain('href="/lien-he"')
                ->and($four)->toContain('href="/lien-he"')->toMatch('~href="[^"]+/a/library/ho-so-aaaaaaaaaaaa\.pdf" download=""~')
                ->and(substr_count($four, '<a class="about__link'))->toBe(4)
                ->and($fourQueries)->toBe($oneQueries);
        });
    });
});
