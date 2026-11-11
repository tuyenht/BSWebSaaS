<?php

use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * A form's thank-you page (the form's `redirect_entry`) is sent to search engines with noindex: it is only
 * for someone who has just sent the form. Other pages, and the home page, are left as they are. The set of
 * thank-you pages is read once per site into the shared cache, so a page view costs no query once warm.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    Cache::flush();
    $this->siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($this->siteId, function (): void {
        $thanks = StorefrontCards::page('/cam-on', 'Cảm ơn bạn', [['type' => 'richtext', 'props' => ['heading' => 'Cảm ơn', 'paragraphs' => ['Đã nhận.']]]]);
        StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [['type' => 'richtext', 'props' => ['heading' => 'Về chúng tôi', 'paragraphs' => ['Một đoạn.']]]]);
        Form::query()->updateOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active', 'settings' => ['redirect_entry' => $thanks->ulid]]);
    });
});

it('marks the thank-you page noindex and no other page', function (): void {
    $thanks = $this->get('http://mau.platform.test/cam-on')->assertOk()->getContent();
    $other = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    $home = $this->get('http://mau.platform.test/')->assertOk()->getContent();

    expect($thanks)->toContain('<meta name="robots" content="noindex">')
        ->and($other)->not->toContain('name="robots"')
        ->and($home)->not->toContain('name="robots"');
});

it('keeps a page indexable when no form points at it', function (): void {
    app(Tenancy::class)->enter($this->siteId, fn () => Form::query()->where('key', 'contact')->update(['settings' => []]));
    Cache::flush();

    expect($this->get('http://mau.platform.test/cam-on')->assertOk()->getContent())->not->toContain('name="robots"');
});

it('reads the forms once and then answers from the cache', function (): void {
    $this->get('http://mau.platform.test/cam-on')->assertOk();

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, '"forms"')) {
            $queries++;
        }
    });
    $this->get('http://mau.platform.test/cam-on')->assertOk();

    expect($queries)->toBe(0);
});

it('leaves a thank-you page out of sitemap.xml and keeps the other pages', function (): void {
    $xml = $this->get('http://mau.platform.test/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('/gioi-thieu')->not->toContain('/cam-on');
});

it('counts only forms that are active as thank-you pages', function (): void {
    app(Tenancy::class)->enter($this->siteId, fn () => Form::query()->where('key', 'contact')->update(['status' => 'disabled']));
    Cache::flush();

    expect($this->get('http://mau.platform.test/cam-on')->assertOk()->getContent())->not->toContain('name="robots"');
    expect($this->get('http://mau.platform.test/sitemap.xml')->assertOk()->getContent())->toContain('/cam-on');
});
