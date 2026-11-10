<?php

use App\Modules\Admin\Support\Settings\SetHeadingDecor;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\StyleProps;
use App\Modules\Theme\Rendering\BlockRenderer;
use App\Modules\Theme\Rendering\PageRenderer;
use App\Modules\Theme\Theming\HeadingDecor;
use App\Modules\Theme\Theming\StorefrontSettings;
use App\Modules\Theme\Theming\ThemeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Tests\Fixtures\StorefrontCards;

/*
 * The decoration under a section heading: a site-wide setting (theme.heading_decor) and a block
 * choice (heading_decor: inherit, none or one of the platform's shapes). With nothing set every
 * heading draws the short rule it always drew, byte for byte.
 */

beforeEach(function (): void {
    seedTenancy();
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    StorefrontCards::pinMediaConfig();
});

const DECOR_HEADING = 'Đẳng cấp và uy tín nhé';

/** @param  array<string, mixed>  $setting */
function decorSet(int $siteId, array $setting): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'theme', 'key' => 'heading_decor'], ['value' => $setting]));
}

/** @param  array<string, mixed>  $props */
function decorFaq(int $siteId, array $props = []): string
{
    app()->setLocale('vi');

    return app(Tenancy::class)->enter($siteId, fn (): string => View::make('theme::sections.faq', ['props' => ['heading' => DECOR_HEADING, 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']]] + $props])->render());
}

/** @param  list<array<string, mixed>>  $blocks */
function decorBlocks(int $siteId, array $blocks): string
{
    app()->setLocale('vi');

    return app(Tenancy::class)->enter($siteId, fn (): string => app(BlockRenderer::class)->render(['blocks' => $blocks]));
}

function decorAdmin(int $siteId = 1, bool $allowed = true): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($user, 'site-admin', $allowed ? ['settings.manage'] : ['entries.view']));

    return $user->fresh();
}

function decorPng(int $siteId, string $slug, string $mime = 'image/png'): Media
{
    return app(Tenancy::class)->enter($siteId, function () use ($slug, $mime): Media {
        $hash = hash('sha256', $slug);

        return Media::query()->create([
            'collection' => 'brand', 'slug' => $slug, 'hash' => $hash,
            'storage_key' => 's/brand/'.$slug.'-'.substr($hash, 0, 12).'.png',
            'mime' => $mime, 'size' => 1_000, 'width' => 320, 'height' => 32,
        ]);
    });
}

describe('with nothing set', function (): void {
    it('renders every head exactly as before', function (): void {
        app()->setLocale('vi');
        $golden = fn (string $name): string => file_get_contents(__DIR__.'/../../Fixtures/heading-decor/'.$name.'.html');
        $steps = [['title' => 'Đầu tiên ạ', 'text' => 'Ổn định ạ'], ['title' => 'Đường đi ạ', 'text' => 'Ấm áp ạ'], ['title' => 'Ước mơ ạ', 'text' => 'Ứng dụng ạ']];

        expect(View::make('theme::sections.faq', ['props' => ['heading' => 'Ưu điểm nổi bật ạ', 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']]]])->render())->toBe($golden('faq'))
            ->and(View::make('theme::sections.features', ['props' => ['heading' => 'Đẳng cấp nhé', 'description' => 'Đoạn mô tả ngắn ạ', 'items' => [['title' => 'Đầu tiên ạ', 'text' => 'Ổn định ạ']]]])->render())->toBe($golden('features'))
            ->and(View::make('theme::sections.stats', ['props' => ['heading' => 'Đếm số ấn tượng ạ', 'items' => [['value' => 12, 'label' => 'Ấn tượng ạ']]]])->render())->toBe($golden('stats'))
            ->and(View::make('theme::sections.process', ['props' => ['heading' => 'Đúng quy trình ạ', 'steps' => $steps]])->render())->toBe($golden('process'))
            ->and(View::make('theme::sections.process', ['props' => ['steps' => $steps]])->render())->toBe($golden('process_bare'));
    });

    it('renders the related lists of a post and a product exactly as before', function (): void {
        app()->setLocale('vi');
        $cards = [['title' => 'Đọc bài liên quan ạ', 'url' => '/bai-1', 'excerpt' => '', 'label' => '']];
        // The head now comes from the shared partial, whose own lines are indented for the sections; only the
        // white space between tags differs from the old hand-copied markup, so that is what is left out.
        $related = static fn (string $html): string => preg_replace('~>\s+<~', '><', preg_match('~<section class="post-related.*?</section>~s', $html, $m) === 1 ? $m[0] : '');
        $golden = static fn (string $name): string => preg_replace('~>\s+<~', '><', file_get_contents(__DIR__.'/../../Fixtures/heading-decor/'.$name.'.html'));

        $post = app(PageRenderer::class)->render('post', null, 'Đầu bài ạ', ['blocks' => []], [], null, ['related' => $cards]);
        $product = app(PageRenderer::class)->renderProduct('Ống sản phẩm ạ', '<p>Ổn mô tả ạ</p>', [], [], ['related' => $cards]);

        expect($related($post))->toBe($golden('post_related'))
            ->and($related($product))->toBe($golden('product_related'));
    });

    it('prints no decoration variable into the head', function (): void {
        $keys = array_keys(app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables()));

        expect(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'sf-decor-')))->toBe([]);
    });
});

describe('the block choice', function (): void {
    it('draws the class of every shape, and a bare rule for inherit', function (): void {
        foreach (HeadingDecor::STYLES as $style) {
            $html = decorFaq(1, ['heading_decor' => $style]);
            expect($html)->toContain('<span class="sf-decor sf-decor--'.$style.'" aria-hidden="true"></span>')
                ->not->toContain('sf-section-head__rule');
        }

        expect(decorFaq(1, ['heading_decor' => 'inherit']))->toContain('<span class="sf-section-head__rule" aria-hidden="true"></span>')->not->toContain('sf-decor');
    });

    it('draws nothing for none, and the plain rule for a value that is not listed', function (): void {
        expect(decorFaq(1, ['heading_decor' => 'none']))->not->toContain('sf-section-head__rule')->not->toContain('sf-decor')->toContain(DECOR_HEADING);

        foreach (['neon', '', 42, ['wave'], 'wave" onclick="x', 'SOME-CLASS'] as $odd) {
            expect(decorFaq(1, ['heading_decor' => $odd]))->toContain('<span class="sf-section-head__rule" aria-hidden="true"></span>')
                ->not->toContain('sf-decor')->not->toContain('onclick');
        }
    });

    it('follows the site setting for inherit, and is overridden by a block that names a shape or none', function (): void {
        decorSet(1, ['style' => 'laurel', 'width' => 'wide', 'weight' => 'bold', 'gradient' => true]);

        expect(decorFaq(1))->toContain('class="sf-decor sf-decor--laurel sf-decor--wide sf-decor--bold sf-decor--grad"')
            ->and(decorFaq(1, ['heading_decor' => 'inherit']))->toContain('sf-decor--laurel')
            ->and(decorFaq(1, ['heading_decor' => 'gear']))->toContain('class="sf-decor sf-decor--gear sf-decor--wide sf-decor--bold sf-decor--grad"')->not->toContain('laurel')
            ->and(decorFaq(1, ['heading_decor' => 'none']))->not->toContain('sf-decor')->not->toContain('sf-section-head__rule')
            ->and(decorFaq(2))->toContain('sf-section-head__rule')->not->toContain('sf-decor');   // another site keeps its rule
    });

    it('lets a site choose no decoration at all', function (): void {
        decorSet(1, ['style' => 'none']);

        expect(decorFaq(1))->not->toContain('sf-decor')->not->toContain('sf-section-head__rule')
            ->and(decorFaq(1, ['heading_decor' => 'wave']))->toContain('sf-decor--wave');
    });

    it('draws the post and product related lists through the same head', function (): void {
        decorSet(1, ['style' => 'wave']);
        $cards = [['title' => 'Đọc bài liên quan ạ', 'url' => '/bai-1', 'excerpt' => '', 'label' => '']];

        [$post, $product] = app(Tenancy::class)->enter(1, fn (): array => [
            app(PageRenderer::class)->render('post', null, 'Đầu bài ạ', ['blocks' => []], [], null, ['related' => $cards]),
            app(PageRenderer::class)->renderProduct('Ống sản phẩm ạ', '<p>Ổn mô tả ạ</p>', [], [], ['related' => $cards]),
        ]);

        expect($post)->toContain('<h2 class="sf-section-head__title" id="bai-viet-lien-quan">')->toContain('sf-decor sf-decor--wave')
            ->and($product)->toContain('<h2 class="sf-section-head__title" id="san-pham-cung-nhom">')->toContain('sf-decor sf-decor--wave');
    });
});

describe('the block schema', function (): void {
    it('offers the choice only on blocks that draw a section heading', function (): void {
        $registry = app(ComponentRegistry::class);
        $headed = ['features', 'partners', 'process', 'pricing', 'testimonial', 'stats', 'gallery', 'quick_links', 'team', 'video', 'faq', 'entry_grid', 'product_grid'];

        foreach ($registry->types() as $type) {
            $schema = $registry->schemaFor($type);
            if (in_array($type, $headed, true)) {
                expect($schema['heading_decor'])->toBe(StyleProps::SCHEMA['heading_decor'])
                    ->and($schema['heading_decor']['default'])->toBe('inherit')
                    ->and($schema['heading_decor']['options'])->toBe(['inherit', 'none', ...HeadingDecor::STYLES]);
            } else {
                expect($schema)->not->toHaveKey('heading_decor', "{$type} has no section heading");
            }
        }

        expect($registry->schemaFor('richtext'))->not->toHaveKey('heading_decor')
            ->and($registry->schemaFor('cta'))->not->toHaveKey('heading_decor')
            ->and(StyleProps::RESERVED)->toContain('heading_decor');
    });

    it('checks the choice at save', function (): void {
        $valid = fn (string $type, array $props): bool => Validator::make(['blocks' => [['type' => $type, 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->passes();

        expect($valid('faq', ['heading' => DECOR_HEADING, 'heading_decor' => 'wave']))->toBeTrue()
            ->and($valid('faq', ['heading' => DECOR_HEADING, 'heading_decor' => 'none']))->toBeTrue()
            ->and($valid('faq', ['heading' => DECOR_HEADING, 'heading_decor' => 'neon']))->toBeFalse()
            ->and($valid('entry_grid', ['heading' => DECOR_HEADING, 'heading_decor' => 'gear']))->toBeTrue();
    });

    it('has a label for every option in every language', function (): void {
        foreach (['vi', 'en', 'ja', 'zh'] as $lang) {
            $strings = json_decode(file_get_contents(base_path("lang/{$lang}.json")), true, 512, JSON_THROW_ON_ERROR);
            foreach (StyleProps::SCHEMA['heading_decor']['options'] as $option) {
                expect($strings)->toHaveKey('entries.opt_heading_decor_'.$option);
                expect(trim($strings['entries.opt_heading_decor_'.$option]))->not->toBe('');
            }
        }
    });
});

describe('colours', function (): void {
    it('prints the site colours as variables in the head', function (): void {
        decorSet(1, ['style' => 'wave', 'color' => 'custom', 'custom_color' => '#C0392B', 'gradient' => true, 'custom_color_2' => '#f39c12']);
        $variables = app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables());

        expect($variables['sf-decor-c'])->toBe('#c0392b')->and($variables['sf-decor-c2'])->toBe('#f39c12');

        decorSet(1, ['style' => 'wave', 'color' => '600']);
        expect(app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables())['sf-decor-c'])->toBe('var(--color-brand-600)');

        decorSet(1, ['style' => 'wave', 'color' => 'accent']);
        expect(app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables()))->not->toHaveKey('sf-decor-c');
    });

    it('never prints a stored colour that is not a hex value, and a stored token cannot set the variable', function (): void {
        foreach (['red', '#12', 'url(x)', '#fff;}</style><script>', 'var(--x)', "#ff0000\n;color:red", 7] as $bad) {
            decorSet(1, ['style' => 'wave', 'color' => 'custom', 'custom_color' => $bad, 'gradient' => true, 'custom_color_2' => $bad]);
            $variables = app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables());
            $page = app(Tenancy::class)->enter(1, fn () => app(PageRenderer::class)->render('page', null, 'Đầu trang ạ', ['blocks' => []]));

            expect($variables)->not->toHaveKey('sf-decor-c')->not->toHaveKey('sf-decor-c2')
                ->and($page)->not->toContain('--sf-decor-c')->not->toContain('<script>');
        }

        decorSet(1, ['style' => 'wave']);
        app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['sf-decor-c' => '#123456', 'sf-decor-mask' => 'url(https://evil.test/x.png)']]));
        app(StorefrontSettings::class)->forget();

        expect(app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables()))->not->toHaveKey('sf-decor-c')->not->toHaveKey('sf-decor-mask');
    });

    it('refuses a bad colour or option when the setting is saved, and writes nothing', function (): void {
        $this->actingAs(decorAdmin());
        $save = fn (array $input) => fn () => app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle($input));

        foreach ([
            [['style' => 'wave', 'color' => 'custom', 'custom_color' => 'red'], 'color_invalid: custom_color'],
            [['style' => 'wave', 'color' => 'custom', 'custom_color' => '#12345'], 'color_invalid: custom_color'],
            [['style' => 'wave', 'color' => 'custom'], 'color_invalid: custom_color'],
            [['style' => 'wave', 'color' => 'custom', 'custom_color' => '#ff0000', 'gradient' => true], 'color_invalid: custom_color_2'],
            [['style' => 'wave', 'gradient' => true, 'custom_color_2' => 'url(x)'], 'color_invalid: custom_color_2'],
            [['style' => 'wave', 'color' => 'teal'], 'color_invalid: color'],
            [['style' => 'sparkle'], 'option_invalid: style'],
            [['style' => 'wave', 'width' => 'huge'], 'option_invalid: width'],
            [['style' => 'wave', 'weight' => 'black'], 'option_invalid: weight'],
            [['style' => 'wave', 'align' => 'right'], 'option_invalid: align'],
            [['style' => 'wave', 'gradient' => 'yes'], 'option_invalid: gradient'],
            [['style' => 'wave', 'padding' => '4px'], 'field_invalid: padding'],
        ] as [$input, $message]) {
            expect($save($input))->toThrow(SettingsChangeRejected::class, $message);
        }

        expect(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'heading_decor')->exists()))->toBeFalse();
    });
});

describe('the mask picture', function (): void {
    it('is the site\'s own PNG, and another site\'s key draws nothing, with two sites interleaved', function (): void {
        $mine = decorPng(1, 'vien-trang-tri');
        $theirs = decorPng(2, 'vien-cua-b');
        decorSet(1, ['mask_media' => $mine->storage_key]);
        decorSet(2, ['mask_media' => $mine->storage_key, 'style' => 'wave']);   // site 2 names site 1's picture
        $url = 'https://files.test/'.$mine->storage_key;

        foreach (range(1, 3) as $_) {
            $a = app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables());
            $htmlA = decorFaq(1);
            $b = app(Tenancy::class)->enter(2, fn () => app(ThemeResolver::class)->cssVariables());
            $htmlB = decorFaq(2);

            expect($a['sf-decor-mask'])->toBe('url('.$url.')')
                ->and($htmlA)->toContain('class="sf-decor sf-decor--mask"')
                ->and($b)->not->toHaveKey('sf-decor-mask')
                ->and($htmlB)->toContain('class="sf-decor sf-decor--wave"')->not->toContain('sf-decor--mask')->not->toContain($mine->storage_key);
        }

        decorSet(2, ['mask_media' => $theirs->storage_key]);
        expect(app(Tenancy::class)->enter(2, fn () => app(ThemeResolver::class)->cssVariables())['sf-decor-mask'])->toBe('url(https://files.test/'.$theirs->storage_key.')');
    });

    it('draws as the plain picture when the colour is kept, and is ignored when it is not a PNG, trashed or oddly shaped', function (): void {
        $png = decorPng(1, 'vien');
        decorSet(1, ['mask_media' => $png->storage_key, 'mask_keep_color' => true, 'gradient' => true]);
        expect(decorFaq(1))->toContain('class="sf-decor sf-decor--image"');

        $webp = decorPng(1, 'vien-webp', 'image/webp');
        decorSet(1, ['mask_media' => $webp->storage_key]);
        expect(decorFaq(1))->toContain('sf-section-head__rule')->not->toContain('sf-decor');

        foreach (['x" onload="y', "a\nb", 'https://evil.test/x.png', '../../etc/passwd'] as $odd) {
            decorSet(1, ['mask_media' => $odd]);
            $variables = app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables());
            expect($variables)->not->toHaveKey('sf-decor-mask')->and(decorFaq(1))->toContain('sf-section-head__rule');
        }

        decorSet(1, ['mask_media' => $png->storage_key]);
        app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($png->id)->first()->delete());
        expect(decorFaq(1))->toContain('sf-section-head__rule')->not->toContain('sf-decor');
    });

    it('is accepted at save only for this site\'s PNG', function (): void {
        $mine = decorPng(1, 'vien');
        $theirs = decorPng(2, 'vien-b');
        $webp = decorPng(1, 'vien-webp', 'image/webp');
        $this->actingAs(decorAdmin());
        $save = fn (string $key) => fn () => app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle(['style' => 'wave', 'mask_media' => $key]));

        foreach ([$theirs->storage_key, $webp->storage_key, 'no/such/key.png', 'bad key"'] as $key) {
            expect($save($key))->toThrow(SettingsChangeRejected::class, 'image_invalid: mask_media');
        }

        $save($mine->storage_key)();
        expect(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'heading_decor')->first()->value['mask_media']))->toBe($mine->storage_key);
    });
});

describe('a page that draws its heads before the tokens', function (): void {
    it('gives each site its own decoration, never the one drawn before', function (): void {
        decorSet(1, ['style' => 'laurel']);
        decorSet(2, ['style' => 'gear']);

        expect(decorFaq(1))->toContain('sf-decor--laurel')->not->toContain('sf-decor--gear')
            ->and(decorFaq(2))->toContain('sf-decor--gear')->not->toContain('sf-decor--laurel')
            ->and(decorFaq(1))->toContain('sf-decor--laurel');

        decorSet(2, ['style' => 'none']);
        expect(decorFaq(2))->not->toContain('sf-decor')->not->toContain('sf-section-head__rule');
    });

    it('does not keep the view factory holding a site\'s decoration', function (): void {
        decorSet(1, ['style' => 'laurel']);
        app(Tenancy::class)->enter(1, fn () => app(ThemeResolver::class)->cssVariables());

        expect(View::getShared())->not->toHaveKey('sfHeadingDecor');
    });

    it('drops the decoration the tokens used when the request ends', function (): void {
        decorSet(1, ['style' => 'laurel']);
        decorSet(2, ['style' => 'gear']);
        $used = app(Tenancy::class)->enter(1, function (): HeadingDecor {
            app(ThemeResolver::class)->cssVariables();

            return app(HeadingDecor::class);
        });

        app()->forgetScopedInstances();

        expect(app(HeadingDecor::class))->not->toBe($used)
            ->and(decorFaq(2))->toContain('sf-decor--gear')->not->toContain('sf-decor--laurel');
    });
});

describe('saving a mask', function (): void {
    it('needs no shape, and a shape saved after it replaces the mask', function (): void {
        $mine = decorPng(1, 'vien-rieng');
        $this->actingAs(decorAdmin());
        $save = fn (array $input) => app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle($input));
        $stored = fn (): array => app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'heading_decor')->first()->value);

        $save(['mask_media' => $mine->storage_key]);
        expect($stored()['style'])->toBe('')->and($stored()['mask_media'])->toBe($mine->storage_key)
            ->and(decorFaq(1))->toContain('class="sf-decor sf-decor--mask"')->not->toContain('sf-section-head__rule');

        $save(['style' => 'wave']);
        expect($stored()['mask_media'])->toBe('')
            ->and(decorFaq(1))->toContain('class="sf-decor sf-decor--wave"')->not->toContain('sf-decor--mask');
    });

    it('wins over a saved shape when both are set', function (): void {
        $mine = decorPng(1, 'vien-rieng');
        $this->actingAs(decorAdmin());
        app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle(['style' => 'wave', 'mask_media' => $mine->storage_key]));

        expect(decorFaq(1))->toContain('class="sf-decor sf-decor--mask"')->not->toContain('sf-decor--wave')
            ->and(decorFaq(1, ['heading_decor' => 'gear']))->toContain('sf-decor--gear');
    });
});

describe('the ground', function (): void {
    it('puts the decoration on the set\'s own colour on a dark or brand ground, even with a custom colour and a gradient', function (): void {
        decorSet(1, ['style' => 'wave', 'color' => 'custom', 'custom_color' => '#ff0000', 'gradient' => true, 'custom_color_2' => '#00ff00']);

        foreach (['dark', 'brand'] as $scheme) {
            $html = decorBlocks(1, [['type' => 'faq', 'props' => ['heading' => DECOR_HEADING, 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']], 'bg' => 'scheme', 'scheme' => $scheme]]]);
            expect($html)->toContain('sf-s--sch-'.$scheme)->toContain('class="sf-decor sf-decor--wave sf-decor--grad"');
        }

        // The stylesheet repaints the decoration with the set's heading colour for exactly those grounds.
        $css = file_get_contents(base_path('resources/css/storefront.css'));
        expect($css)->toContain(":is(.sf-band--dark, .sf-s--sch-dark, .sf-s--sch-brand, .sf-s--image) .sf-decor {\n    --sf-decor-c: var(--sch-head, var(--color-surface));\n    --sf-decor-c2: var(--sch-head, var(--color-surface));\n}")
            ->and($css)->toContain(".sf-band--dark .sf-decor {\n    --sf-decor-c: var(--color-surface);");
    });

    it('limits the width to 70% of the frame and hides the decoration from assistive technology', function (): void {
        $css = file_get_contents(base_path('resources/css/storefront.css'));

        expect($css)->toContain("    width: var(--sf-decor-w, 15rem);\n    max-width: 70%;")
            ->and(decorFaq(1, ['heading_decor' => 'star']))->toContain('class="sf-decor sf-decor--star" aria-hidden="true"');

        foreach (HeadingDecor::STYLES as $style) {
            expect($css)->toContain(".sf-decor--{$style} {\n    --sf-decor-shape: url(\"data:image/svg+xml");
        }
    });
});

describe('the setting', function (): void {
    it('is saved behind the settings permission and refused without it', function (): void {
        $this->actingAs(decorAdmin(1, allowed: false));

        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle(['style' => 'wave'])))->toThrow(AuthorizationException::class);
        expect(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'heading_decor')->exists()))->toBeFalse();

        auth()->logout();
        $this->actingAs(decorAdmin());
        app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle(['style' => 'wave', 'color' => '700', 'width' => 'wide', 'weight' => 'light', 'align' => 'center']));

        $stored = app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'theme')->where('key', 'heading_decor')->first()->value);
        expect($stored)->toEqual([
            'style' => 'wave', 'color' => '700', 'custom_color' => '', 'gradient' => false, 'custom_color_2' => '',
            'align' => 'center', 'width' => 'wide', 'weight' => 'light', 'mask_media' => '', 'mask_keep_color' => false,
        ])->and(decorFaq(1))->toContain('class="sf-decor sf-decor--wave sf-decor--wide sf-decor--light"');

        // Saving nothing brings the plain rule back; the other site never changed.
        app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle([]));
        expect(decorFaq(1))->toContain('sf-section-head__rule')->not->toContain('sf-decor');
    });

    it('leaves another site\'s setting alone', function (): void {
        decorSet(2, ['style' => 'gear']);
        $this->actingAs(decorAdmin());
        app(Tenancy::class)->enter(1, fn () => app(SetHeadingDecor::class)->handle(['style' => 'wave']));

        expect(decorFaq(2))->toContain('sf-decor--gear')->and(decorFaq(1))->toContain('sf-decor--wave');
    });
});

describe('queries', function (): void {
    it('reads no more for a page with decorated headings than for one without', function (): void {
        $blocks = [
            ['type' => 'faq', 'props' => ['heading' => DECOR_HEADING, 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']]]],
            ['type' => 'faq', 'props' => ['heading' => DECOR_HEADING, 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']], 'heading_decor' => 'zigzag']],
            ['type' => 'features', 'props' => ['heading' => DECOR_HEADING, 'items' => [['title' => 'Đầu tiên ạ', 'text' => 'Ổn định ạ']]]],
        ];
        $queries = function () use ($blocks): int {
            app(StorefrontSettings::class)->forget();
            DB::flushQueryLog();
            DB::enableQueryLog();
            decorBlocks(1, $blocks);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $plain = $queries();
        decorSet(1, ['style' => 'wave', 'color' => 'custom', 'custom_color' => '#ff0000', 'gradient' => true, 'custom_color_2' => '#00ff00']);
        $decorated = $queries();

        expect($decorated)->toBe($plain);
    });

    it('adds at most one media read for a mask, however many heads the page has', function (): void {
        $png = decorPng(1, 'vien-truy-van');
        $count = function (int $heads): int {
            $blocks = array_fill(0, $heads, ['type' => 'faq', 'props' => ['heading' => DECOR_HEADING, 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']]]]);
            app(StorefrontSettings::class)->forget();
            app()->forgetInstance(HeadingDecor::class);   // a request starts without the last one's mask lookup
            DB::flushQueryLog();
            DB::enableQueryLog();
            decorBlocks(1, $blocks);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        decorSet(1, ['style' => 'wave']);
        $plain = $count(1);
        decorSet(1, ['mask_media' => $png->storage_key]);
        $one = $count(1);
        $five = $count(5);

        expect($one - $plain)->toBeLessThanOrEqual(1)->and($five)->toBe($one)
            ->and(decorBlocks(1, [['type' => 'faq', 'props' => ['heading' => DECOR_HEADING, 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']]]]]))->toContain('sf-decor--mask');
    });
});
