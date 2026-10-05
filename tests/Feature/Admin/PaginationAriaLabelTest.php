<?php

use Illuminate\Support\Facades\Blade;

/*
 * The pager's <nav> landmark label must go through __() like its prev/next buttons —
 * otherwise a non-English admin gets an English string read out by a screen reader.
 */

it('renders the pagination nav label through a locale-aware key', function (): void {
    app()->setLocale('vi');

    $html = Blade::render('<x-bs-admin::pagination :current="1" :last="3" />');

    // A literal "Pagination" would never change with the locale — proves it is __()-driven.
    expect($html)->toContain('aria-label="Phân trang"');
});
