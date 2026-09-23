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
      assertions: {
        'categories:performance': ['warn', { minScore: 0.7 }],
        'largest-contentful-paint': ['warn', { maxNumericValue: 1500 }],
        'resource-summary:script:size': ['warn', { maxNumericValue: 30720 }],
      },
    },
    upload: {
      target: 'filesystem',
      outputDir: './.lighthouseci',
    },
  },
};
