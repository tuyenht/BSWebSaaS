// Single source of truth for host/port: reads the same CWV_HOST/CWV_PORT the CI job's
// `env:` block sets, so this file and .github/workflows/ci.yml can never drift apart
// (a JSON config can't reference an env var at all — that was the previous, riskier shape).
const host = process.env.CWV_HOST || 'cwv-ci.test';
const port = process.env.CWV_PORT || '8099';

module.exports = {
  ci: {
    collect: {
      url: [`http://${host}:${port}/`],
      numberOfRuns: 3,
      startServerCommand: `php artisan serve --host=0.0.0.0 --port=${port}`,
      startServerReadyPattern: 'Server running on',
      startServerReadyTimeout: 15000,
      settings: {
        onlyCategories: ['performance'],
        chromeFlags: '--headless=new --no-sandbox --disable-gpu',
      },
    },
    assert: {
      // The two budgets CLAUDE.md fixes for the storefront (LCP ≤ 1.5s, JS ≤ 30KB) GATE the job;
      // calibrated 24/09 on 18 CI samples of this page: LCP 751–1055 ms (two clusters from the
      // simulated throttling), JS 0 bytes — ≥30% headroom. Median of the 3 runs, so one slow
      // run cannot fail it on its own. The overall performance score is not a fixed budget and
      // is noisier; it stays a warning.
      assertions: {
        'categories:performance': ['warn', { minScore: 0.7, aggregationMethod: 'median' }],
        'largest-contentful-paint': ['error', { maxNumericValue: 1500, aggregationMethod: 'median' }],
        'resource-summary:script:size': ['error', { maxNumericValue: 30720, aggregationMethod: 'median' }],
      },
    },
    upload: {
      target: 'filesystem',
      outputDir: './.lighthouseci',
    },
  },
};
