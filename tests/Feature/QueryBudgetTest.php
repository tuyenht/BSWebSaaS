<?php

/*
 * Query Budget guardrail (CLAUDE.md §6): assert a hard ceiling of DB queries per
 * hot route so N+1 regressions fail CI. Tighten as the render path matures (cache,
 * eager-load); for now it bounds the resolve + render of one page.
 */

beforeEach(function () {
    seedTenancy();
    seedHomepage(1, 'An Vinh');
});

it('renders the storefront home within its query budget', function () {
    $budget = 11; // + nav: one assignment lookup (miss → empty menu) on top of the page resolve

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});
