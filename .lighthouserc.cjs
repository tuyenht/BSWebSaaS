// Single source of truth for host/port: reads the same CWV_HOST/CWV_PORT the CI job's
// `env:` block sets, so this file and .github/workflows/ci.yml can never drift apart
// (a JSON config can't reference an env var at all — that was the previous, riskier shape).
const host = process.env.CWV_HOST || 'cwv-ci.test';
const port = process.env.CWV_PORT || '8099';
const origin = `http://${host}:${port}`;

// The storefront budgets CLAUDE.md fixes (LCP <= 1.5s, JS <= 30KB), per page, median of the 3 runs
// (one slow run cannot fail it alone). A page joins at 'warn' and is promoted to 'error' only
// after its own real measurements show headroom — the protocol the homepage went through 24/09.
// The overall performance score is not a fixed budget and is noisier: always a warning.
const budgets = (level) => ({
  'categories:performance': ['warn', { minScore: 0.7, aggregationMethod: 'median' }],
  'largest-contentful-paint': [level, { maxNumericValue: 1500, aggregationMethod: 'median' }],
  'resource-summary:script:size': [level, { maxNumericValue: 30720, aggregationMethod: 'median' }],
});

module.exports = {
  ci: {
    collect: {
      // Pages the provisioned measurement site already publishes: home, the contact page (a form
      // block) and the seeded news category list.
      url: [`${origin}/`, `${origin}/lien-he`, `${origin}/tin-tuc`],
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
      // All three pages gated. Calibration (CI, 24/09): / 18 samples, /lien-he + /tin-tuc 12 each —
      // LCP 750–1055 ms (two clusters from simulated throttling), JS 0 bytes, axe clean.
      // A page added later joins through an assertMatrix entry at 'warn' first.
      assertions: budgets('error'),
    },
    upload: {
      target: 'filesystem',
      outputDir: './.lighthouseci',
    },
  },
};
