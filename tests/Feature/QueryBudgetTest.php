<?php

/*
 * Query Budget guardrail (CLAUDE.md §6).
 * Demonstrates the framework: assert a hard ceiling of DB queries per hot
 * route so N+1 regressions fail CI. Tighten budgets / add hot routes as
 * features land.
 */
it('serves the home route within its query budget', function () {
    $budget = 0; // welcome page touches no DB yet

    $count = countQueries(fn () => $this->get('/')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});
