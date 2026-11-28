<?php

use App\Modules\Admin\Http\Controllers\BuilderPreviewController;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Support\BuilderPreview;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Chrome\StorefrontCsp;
use App\Modules\Theme\Components\BlockReference;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\StorefrontCards;

/*
 * The page builder's preview frame (admin.entries.builder-preview): the real storefront page of an
 * entry drawn from the editor's UNSAVED blocks, posted as a multipart form, for a member who may EDIT
 * the entry. It writes nothing, checks the blocks as a save would, answers within the preview's own
 * headers and CSP, and every refusal (404 / 413 / 419 / 422 / 429) is the frame's small page naming
 * its code (<meta name="bs-pb-error">) so the frame's script can tell the editor. Only this frame
 * marks the blocks (data-pb-i) and names the nonce (<meta name="bs-pb">): a visitor's page and the
 * plain «Xem trước» never do.
 */

const BP_NONCE = '0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    // Print each Vite entry as a module script so the frame's script is visible.
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
});

/** Start the next request from a clean container, as every request does in production. */
function bpFresh(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    app(TeamContext::class)->forget();
}

/** A member of $site holding $permissions. */
function bpMember(array $permissions = ['entries.view', 'entries.edit.any'], int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'role-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/**
 * A draft page of $site whose saved body is $blocks; returns its ulid.
 *
 * @param  list<array<string, mixed>>|null  $blocks
 */
function bpDraft(int $site = 1, string $title = 'Giới thiệu xưởng', ?array $blocks = null, ?User $author = null): string
{
    return app(Tenancy::class)->enter($site, function () use ($title, $blocks, $author): string {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => [], 'author_id' => $author?->id]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title), 'status' => 'draft',
            'body' => ['blocks' => $blocks ?? [bpText('Bản đã lưu của trang.')]],
        ]);

        return $entry->ulid;
    });
}

/** A plain text block saying $words. */
function bpText(string $words): array
{
    return ['type' => 'richtext', 'props' => ['paragraphs' => [$words]]];
}

/**
 * Post the editor's $blocks to the preview of $ulid as $user.
 *
 * @param  list<mixed>|string  $blocks
 * @param  array<string, mixed>  $fields
 * @param  array<string, string>  $headers
 */
function bpPost(User $user, string $ulid, array|string $blocks, array $fields = [], array $headers = [], string $host = 'a.test'): TestResponse
{
    return test()->actingAs($user)->withHeaders($headers)->post("http://{$host}/admin/content/{$ulid}/builder-preview", $fields + [
        'locale' => 'vi',
        'title' => 'Tiêu đề chưa lưu',
        'blocks' => is_string($blocks) ? $blocks : json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'nonce' => BP_NONCE,
    ]);
}

/** The headers every answer of the frame carries, a preview or a refusal. */
function bpFrameHeaders(TestResponse $response): void
{
    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match('/script-src ([^;]*)/', $csp, $scripts);

    expect($csp)->toBe(StorefrontCsp::header()."; frame-ancestors 'self'")
        ->and($scripts[1] ?? '')->not->toBe('')->not->toContain("'unsafe-inline'")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
}

/** A refusal: the frame's small page with its code, the frame's headers, the frame's script, and the nonce only when well formed. */
function bpRefused(TestResponse $response, int $code, bool $withNonce = true): string
{
    $response->assertStatus($code);
    bpFrameHeaders($response);
    $html = (string) $response->getContent();

    expect($html)->toContain('<meta name="bs-pb-error" content="'.$code.'"')
        ->toContain('<script type="module" src="/resources/js/sf-builder-bridge.js"></script>')
        // the page holds no words: the editor says what happened in the member's language
        ->and(trim(strip_tags((string) preg_replace('#<head>.*</head>#s', '', $html))))->toBe('');
    if ($withNonce) {
        expect($html)->toContain('<meta name="bs-pb" content="'.BP_NONCE.'">');
    } else {
        expect($html)->not->toContain('name="bs-pb"');
    }

    return $html;
}

/** Every INSERT, UPDATE or DELETE $callback runs. */
function bpWrites(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();

        return array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('draws the unsaved blocks as the website would, with the preview headers, the frame nonce and each block marked', function (): void {
    $ulid = bpDraft();
    $editor = bpMember();

    $response = bpPost($editor, $ulid, [bpText('Đoạn mở đầu chưa lưu.'), ['type' => 'cta', 'props' => ['heading' => 'Liên hệ báo giá ngay']]])->assertOk();
    bpFrameHeaders($response);
    $html = (string) $response->getContent();

    expect($html)->toContain('Đoạn mở đầu chưa lưu.')
        ->toContain('Liên hệ báo giá ngay')
        ->not->toContain('Bản đã lưu của trang.')
        ->toContain('<meta name="bs-pb" content="'.BP_NONCE.'">')
        ->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('rel="canonical"')
        ->not->toContain('name="sf-view"')
        ->and(substr_count($html, 'data-pb-i="0"'))->toBe(1)
        ->and(substr_count($html, 'data-pb-i="1"'))->toBe(1)
        ->and($html)->not->toContain('data-pb-i="2"');

    // no inline script but the text-size boot the CSP names by its hash, no Turnstile script
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $inline);
    $csp = (string) $response->headers->get('Content-Security-Policy');
    foreach ($inline[1] as $body) {
        expect($csp)->toContain("'sha256-".base64_encode(hash('sha256', $body, true))."'");
    }
    expect(count($inline[1]))->toBeLessThanOrEqual(1)
        ->and($html)->not->toMatch('#<script[^>]+challenges\.cloudflare\.com#');
});

it('writes nothing to the content tables: no translation, revision, route, view or audit row, and the saved body stays', function (): void {
    $ulid = bpDraft();
    $editor = bpMember();
    bpPost($editor, $ulid, [bpText('Khởi động')])->assertOk(); // the member's first request: session and device rows
    bpFresh();

    $writes = bpWrites(fn () => bpPost($editor, $ulid, [bpText('Bản chưa lưu')])->assertOk()->assertSee('Bản chưa lưu'));

    $content = ['entries', 'entry_translations', 'routes', 'entry_revisions', 'entry_pending_changes', 'entry_taxonomies', 'media', 'mediables',
        'content_blocks', 'content_block_translations', 'content_block_usages', 'entry_views', 'entry_view_days', 'audit_logs', 'redirects'];
    foreach ($writes as $sql) {
        foreach ($content as $table) {
            expect($sql)->not->toMatch('/\b(into|update|from)\s+"?'.$table.'"?\b/i');
        }
    }
    // the member's first request above wrote the shared middleware's rows (the signed-in device and
    // its session, TrackSignedInDevice); from then on a preview writes nothing at all
    expect($writes)->toBe([]);
    $saved = app(Tenancy::class)->enter(1, fn () => EntryTranslation::query()->where('entry_id', Entry::query()->where('ulid', $ulid)->value('id'))->sole());
    expect(json_encode($saved->body, JSON_UNESCAPED_UNICODE))->toContain('Bản đã lưu của trang.')->not->toContain('Bản chưa lưu');
});

it('takes a full page of Vietnamese text sent as multipart/form-data, which URL-encoding would have made too large', function (): void {
    $ulid = bpDraft();
    $editor = bpMember();
    $line = 'Xưởng cơ khí chính xác gia công chi tiết máy theo bản vẽ của khách hàng, kiểm tra từng lô hàng. ';
    $blocks = array_map(static fn (int $i): array => bpText(str_repeat($line, 500).'Khối '.$i), range(1, 4));
    $json = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fields = ['_token' => str_repeat('t', 40), 'locale' => 'vi', 'title' => 'Tiêu đề chưa lưu', 'blocks' => $json, 'nonce' => BP_NONCE, 'scroll' => '0:0'];
    $boundary = '----bsBuilderFrame';
    $multipart = '';
    foreach ($fields as $name => $value) {
        $multipart .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
    $multipart .= "--{$boundary}--\r\n";

    expect(strlen($json))->toBeGreaterThan(240_000)->toBeLessThan(ValidPageJson::MAX_BYTES)
        ->and(strlen($multipart))->toBeLessThan(BuilderPreviewController::MAX_REQUEST_BYTES)
        ->and(strlen(http_build_query($fields)))->toBeGreaterThan(BuilderPreviewController::MAX_REQUEST_BYTES);

    bpPost($editor, $ulid, $json, headers: ['Content-Type' => 'multipart/form-data; boundary='.$boundary, 'Content-Length' => (string) strlen($multipart)])
        ->assertOk()->assertSee('Khối 4');
});

it('answers a member who may only see the entry, a colleague’s draft to an own-only editor, another site’s entry, one in the Trash and a language the site has off with the same 404', function (): void {
    $editor = bpMember();
    $viewer = bpMember(['entries.view']);
    $ownOnly = bpMember(['entries.view', 'entries.edit.own']);
    $colleague = bpMember(['entries.view', 'entries.edit.own']);
    $mine = bpDraft();
    $theirs = bpDraft(title: 'Bản của đồng nghiệp', author: $colleague);
    $other = bpDraft(site: 2, title: 'Trang của site khác');

    bpRefused(bpPost($viewer, $mine, [bpText('x')]), 404);
    bpFresh();
    bpRefused(bpPost($ownOnly, $theirs, [bpText('x')]), 404);
    bpFresh();
    bpRefused(bpPost($editor, $other, [bpText('x')]), 404);
    bpFresh();
    bpRefused(bpPost($editor, $mine, [bpText('x')], ['locale' => 'en']), 404);
    bpFresh();
    bpRefused(bpPost($editor, $mine, [bpText('x')], ['locale' => ['vi']]), 404);
    bpFresh();
    app(Tenancy::class)->enter(1, fn () => Entry::query()->where('ulid', $mine)->firstOrFail()->delete());
    bpRefused(bpPost($editor, $mine, [bpText('x')]), 404);
    bpFresh();
    // the same member is let through on a page they may edit
    bpPost($ownOnly, bpDraft(title: 'Bản của tôi', author: $ownOnly), [bpText('Của tôi chưa lưu')])->assertOk()->assertSee('Của tôi chưa lưu');
});

it('draws a language the site has on that the entry has no translation in yet from the posted title and blocks, creating none', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $ulid = bpDraft();
    $editor = bpMember();

    $html = (string) bpPost($editor, $ulid, [bpText('English words not saved yet.')], ['locale' => 'en', 'title' => 'About our workshop'])
        ->assertOk()->getContent();

    expect($html)->toContain('English words not saved yet.')
        ->toContain('About our workshop')
        ->toContain('<html lang="en"')
        ->and(app(Tenancy::class)->enter(1, fn () => EntryTranslation::query()->where('locale', 'en')->count()))->toBe(0);
});

it('refuses a post without the session’s CSRF token with the frame’s 419 page, and takes it with the token', function (): void {
    // the test runner skips the CSRF check; this binding puts it back for this test
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $ulid = bpDraft();
    $editor = bpMember();

    bpRefused(bpPost($editor, $ulid, [bpText('x')]), 419);
    bpFresh();
    bpPost($editor, $ulid, [bpText('Có mã CSRF')], ['_token' => 'mã-phiên'], ['X-Test' => '1'])->assertStatus(419);
    bpFresh();
    test()->withSession(['_token' => 'ma-phien-dung']);
    bpPost($editor, $ulid, [bpText('Có mã CSRF')], ['_token' => 'ma-phien-dung'])->assertOk()->assertSee('Có mã CSRF');
});

it('refuses a request over 400 KB and blocks past the body’s ceiling plus the form’s margin with 413, before decoding them', function (): void {
    $ulid = bpDraft();
    $editor = bpMember();

    bpRefused(bpPost($editor, $ulid, [bpText('nhỏ')], headers: ['Content-Length' => (string) (BuilderPreviewController::MAX_REQUEST_BYTES + 1)]), 413);
    bpFresh();
    // not even JSON: refused on its length alone, though the request (sent as multipart) is under 400 KB
    $over = str_repeat('[', ValidPageJson::MAX_BYTES + BuilderPreviewController::FORM_MARGIN_BYTES + 1);
    bpRefused(bpPost($editor, $ulid, $over, headers: ['Content-Length' => (string) (strlen($over) + 1024)]), 413);
    bpFresh();
    // right at the margin it is read (and refused as the save would: not a block list); the test
    // client sends the form URL-encoded, so the length a multipart form of it has is named
    $atMargin = str_repeat('[', ValidPageJson::MAX_BYTES + BuilderPreviewController::FORM_MARGIN_BYTES);
    bpRefused(bpPost($editor, $ulid, $atMargin, headers: ['Content-Length' => (string) (strlen($atMargin) + 1024)]), 422);
});

it('lets a member preview 120 times a minute on a site, then answers 429 with the wait, and keeps another member’s count apart', function (): void {
    $ulid = bpDraft();
    $editor = bpMember();
    $colleague = bpMember();

    // cheap refusals count too: the limiter runs before the preview
    for ($i = 1; $i <= BuilderPreviewController::PER_MINUTE; $i++) {
        bpPost($editor, $ulid, [bpText('x')], ['nonce' => 'sai'])->assertStatus(422);
        bpFresh();
    }
    $html = bpRefused(bpPost($editor, $ulid, [bpText('x')]), 429);
    expect($html)->toMatch('/<meta name="bs-pb-error" content="429" data-retry="([1-9]\d?)">/');
    bpFresh();

    bpPost($colleague, $ulid, [bpText('Đồng nghiệp vẫn xem được')])->assertOk();
});

it('refuses with 422 what a save would refuse: an unknown block, a javascript: link, 101 blocks, another site’s shared block or picture, a broken list', function (array|string $blocks): void {
    StorefrontCards::pinMediaConfig();
    $ulid = bpDraft();
    $editor = bpMember();
    $foreign = app(Tenancy::class)->enter(2, function (): array {
        $block = ContentBlock::query()->create(['key' => 'khac', 'name' => 'Khối site khác', 'region' => 'inline', 'status' => 'published']);
        $media = StorefrontCards::media('anh-site-khac');

        return ['block' => $block->ulid, 'key' => $media->storage_key];
    });
    $blocks = is_string($blocks) ? $blocks : json_decode(str_replace(['@block', '@key'], [$foreign['block'], $foreign['key']], (string) json_encode($blocks)), true);

    bpRefused(bpPost($editor, $ulid, $blocks), 422);
})->with([
    'an unknown block' => [[['type' => 'khong_co_khoi_nay', 'props' => []]]],
    'a javascript: link' => [[['type' => 'cta', 'props' => ['heading' => 'x', 'button_url' => 'javascript:alert(document.cookie)']]]],
    '101 blocks' => [array_fill(0, 101, ['type' => 'cta', 'props' => ['heading' => 'x']])],
    'another site’s shared block' => [[['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => '@block']]]],
    'another site’s picture' => [[['type' => 'richtext_html', 'props' => ['html' => '<p>Ảnh</p><img src="https://files.test/@key" alt="x">']]]],
    'not a list' => ['{"type":"cta"}'],
    'not JSON' => ['[{"type":'],
    'a block that is not an object' => ['["cta"]'],
]);

it('draws a picture of the site’s own library placed in a block of HTML, the way a save stores it', function (): void {
    StorefrontCards::pinMediaConfig();
    $ulid = bpDraft();
    $editor = bpMember();
    $key = app(Tenancy::class)->enter(1, fn (): string => StorefrontCards::media('anh-cua-minh')->storage_key);

    bpPost($editor, $ulid, [['type' => 'richtext_html', 'props' => ['html' => '<p>Ảnh</p><img src="https://files.test/'.$key.'" alt="Ảnh của mình">']]])
        ->assertOk()->assertSee('alt="Ảnh của mình"', false)->assertSee('anh-cua-minh-', false);
});

it('refuses a malformed nonce with 422 and never prints it', function (string $nonce): void {
    $ulid = bpDraft();
    $editor = bpMember();

    $html = bpRefused(bpPost($editor, $ulid, [bpText('x')], ['nonce' => $nonce]), 422, withNonce: false);

    expect($html)->not->toContain($nonce);
})->with([
    'too long' => [BP_NONCE.'00'],
    'upper case' => [strtoupper(BP_NONCE)],
    'a quote' => ['0123456789abcdef0123456789abcde"'],
    'a tag' => ['0123456789abcdef0123456789abcd<b'],
]);

it('marks the blocks only in the builder’s frame: never on the website, with ?marks=1 or not, nor in the plain «Xem trước»', function (): void {
    $blocks = [bpText('Trang chủ'), ['type' => 'cta', 'props' => ['heading' => 'Gọi ngay']]];
    // even a stored body that somehow holds the frame's keys draws no mark for a visitor
    $stored = [[...$blocks[0], BuilderPreview::INDEX => 0], ['type' => BuilderPreview::EMPTY_TYPE, 'props' => [], BuilderPreview::INDEX => 1, 'state' => 'draft'], $blocks[1]];
    $home = app(Tenancy::class)->enter(1, fn () => StorefrontCards::page('/', 'Trang chủ', $stored)->ulid);
    $editor = bpMember();

    foreach (['http://a.test/', 'http://a.test/?marks=1'] as $url) {
        $html = (string) $this->get($url)->assertOk()->getContent();
        expect($html)->toContain('Gọi ngay')->not->toContain('data-pb-')->not->toContain('name="bs-pb"');
        bpFresh();
    }
    $html = (string) $this->actingAs($editor)->get("http://a.test/admin/content/{$home}/preview")->assertOk()->getContent();
    expect($html)->toContain('Gọi ngay')->not->toContain('data-pb-')->not->toContain('name="bs-pb"');
    bpFresh();

    expect((string) bpPost($editor, $home, $blocks)->assertOk()->getContent())->toContain('data-pb-i="1"');
});

it('gives a shared block’s blocks the place of the reference, and leaves an empty mark for a draft or a gone one only in the frame', function (): void {
    [$shared, $draft, $gone] = app(Tenancy::class)->enter(1, function (): array {
        $shared = ContentBlock::query()->create(['key' => 'chung', 'name' => 'Khối chung', 'region' => 'inline', 'status' => 'published']);
        ContentBlockTranslation::query()->create(['content_block_id' => $shared->id, 'locale' => 'vi', 'body' => ['blocks' => [
            ['type' => 'cta', 'props' => ['heading' => 'Phần chung một']],
            ['type' => 'cta', 'props' => ['heading' => 'Phần chung hai']],
        ]]]);
        $draft = ContentBlock::query()->create(['key' => 'nhap', 'name' => 'Khối nháp', 'region' => 'inline', 'status' => 'draft']);
        $gone = ContentBlock::query()->create(['key' => 'mat', 'name' => 'Khối sắp xóa', 'region' => 'inline', 'status' => 'published']);

        return [$shared->ulid, $draft->ulid, $gone->ulid];
    });
    $ref = static fn (string $ulid): array => ['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => $ulid]];
    $blocks = [bpText('Mở đầu'), $ref($shared), $ref($draft), $ref($gone), bpText('Kết')];
    // the page stores them all now, so the one deleted below stays allowed (as a save keeps it)
    $home = app(Tenancy::class)->enter(1, fn () => StorefrontCards::page('/', 'Trang chủ', $blocks)->ulid);
    app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->where('ulid', $gone)->delete());
    $editor = bpMember();

    $html = (string) bpPost($editor, $home, $blocks)->assertOk()->getContent();

    expect($html)->toMatch('/<[a-z]+ data-pb-i="1"[^>]*>(?:(?!data-pb-i).)*Phần chung một/s')
        ->toMatch('/<[a-z]+ data-pb-i="1"[^>]*>(?:(?!data-pb-i).)*Phần chung hai/s')
        ->toContain('<div data-pb-i="2" data-pb-empty="draft"></div>')
        ->toContain('<div data-pb-i="3" data-pb-empty="gone"></div>')
        ->and(substr_count($html, 'data-pb-i="4"'))->toBe(1);

    bpFresh();
    $public = (string) $this->get('http://a.test/')->assertOk()->getContent();
    expect($public)->toContain('Phần chung hai')->not->toContain('data-pb-');
});

it('keeps two sites’ previews apart when one worker draws them in turn', function (): void {
    $a = bpDraft(1, 'Trang của A');
    $b = bpDraft(2, 'Trang của B');
    $editorA = bpMember(site: 1);
    $editorB = bpMember(site: 2);

    foreach (range(1, 2) as $round) {
        $html = (string) bpPost($editorA, $a, [bpText("Chưa lưu của A {$round}")])->assertOk()->getContent();
        expect($html)->toContain("Chưa lưu của A {$round}")->not->toContain('của B');
        bpFresh();
        $html = (string) bpPost($editorB, $b, [bpText("Chưa lưu của B {$round}")], host: 'b.test')->assertOk()->getContent();
        expect($html)->toContain("Chưa lưu của B {$round}")->not->toContain('của A');
        bpFresh();
        // A's entry asked for on B's host: B's site has no such entry
        bpRefused(bpPost($editorB, $a, [bpText('x')], host: 'b.test'), 404);
        bpFresh();
    }
});
