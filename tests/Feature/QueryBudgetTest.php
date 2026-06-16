<?php

/*
 * Query Budget guardrail (CLAUDE.md §6): assert a hard ceiling of DB queries per
 * hot route so N+1 regressions fail CI. Tighten budgets / add hot routes as
 * features land (the storefront render budget will get strict in P1).
 */

beforeEach(fn () => seedTenancy());

it('serves the storefront home within its query budget', function () {
    // Skeleton storefront: resolve host -> site (1), set/assert tenant GUC, check
    // site is active (1). No content queries yet — a small fixed ceiling catches N+1.
    $budget = 6;

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});
