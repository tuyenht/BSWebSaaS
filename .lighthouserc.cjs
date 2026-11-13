// Single source of truth for the host: reads the same CWV_HOST the CI job's `env:` block sets,
// so this file and .github/workflows/ci.yml can never drift apart (a JSON config can't reference
// an env var at all — that was the previous, riskier shape). The pages are loaded over HTTPS on
// the standard port: the job serves them from PHP's built-in server on CWV_PORT behind Caddy
// (scripts/cwv-Caddyfile, `tls internal`, HTTP/2), as production serves them over HTTP/2 + TLS.
const host = process.env.CWV_HOST || 'cwv-ci.test';
const origin = `https://${host}`;

// The storefront budgets CLAUDE.md fixes (LCP <= 1.5s, JS <= 30KB), per page, median of the 3 runs
// (one slow run cannot fail it alone). A page joins at 'warn' and is promoted to 'error' only
// after its own real measurements show headroom — the protocol the homepage went through 24/09.
// The overall performance score is not a fixed budget and is noisier: always a warning.
// Accessibility, best practices and SEO are the same on every run and must be 100 on every page from
// the day it joins: what PageSpeed shows a site owner, on a site that has filled in nothing yet (the CI
// site has no brand name or description). The axe step after this one checks each page too, but not
// the way Lighthouse renders it. Measured 13/11 on all nine pages, 6 runs each: best practices 100,
// SEO 100 apart from the page description, which every page now has.
const budgets = (level) => ({
  'categories:performance': ['warn', { minScore: 0.7, aggregationMethod: 'median' }],
  'categories:accessibility': ['error', { minScore: 1, aggregationMethod: 'median' }],
  'categories:best-practices': ['error', { minScore: 1, aggregationMethod: 'median' }],
  'categories:seo': ['error', { minScore: 1, aggregationMethod: 'median' }],
  'largest-contentful-paint': [level, { maxNumericValue: 1500, aggregationMethod: 'median' }],
  'resource-summary:script:size': [level, { maxNumericValue: 30720, aggregationMethod: 'median' }],
});

module.exports = {
  ci: {
    collect: {
      // Pages the provisioned measurement site publishes: home, the contact page (a form block),
      // the news list, and two pages site:seed-measurement adds: /do-kiem (slider, card grids,
      // contact cards) and /ban-tin (a news card grid with child-category chips).
      url: [`${origin}/`, `${origin}/lien-he`, `${origin}/tin-tuc`, `${origin}/do-kiem`, `${origin}/ban-tin`,
        `${origin}/khanh-thanh-xuong-moi`, `${origin}/do-kiem-gioi-thieu`, `${origin}/do-kiem-noi-dung`, `${origin}/do-kiem-thuong-mai`],
      numberOfRuns: 3,
      // No startServerCommand: the job's "Serve the storefront over HTTP/2 + TLS" step has already
      // started PHP's built-in server behind scripts/cwv-serve.php (gzips HTML, CSS and JS the way
      // production's Caddy does, `encode zstd gzip`, so the page weighs what a visitor downloads)
      // and Caddy in front of it, and checked that it answers over HTTP/2.
      settings: {
        onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'],
        // --ignore-certificate-errors: Caddy's `tls internal` certificate is accepted by this
        // Chrome only; nothing is added to the runner's trust store.
        chromeFlags: '--headless=new --no-sandbox --disable-gpu --ignore-certificate-errors',
      },
    },
    assert: {
      // The first three pages gated. Calibration (CI, 24/09): / 18 samples, /lien-he + /tin-tuc 12
      // each — LCP 750–1055 ms (two clusters from simulated throttling), JS 0 bytes, axe clean.
      // A page added later joins at 'warn' with the same budgets: /do-kiem and /ban-tin since
      // 02/11, promoted to 'error' once their own CI samples show headroom.
      assertMatrix: [
        { matchingUrlPattern: '/(do-kiem|ban-tin)$', assertions: budgets('warn') },
        { matchingUrlPattern: '^https?://[^/]+(/|/lien-he|/tin-tuc|/khanh-thanh-xuong-moi|/do-kiem-gioi-thieu|/do-kiem-noi-dung|/do-kiem-thuong-mai)$', assertions: budgets('error') },
      ],
    },
    upload: {
      target: 'filesystem',
      outputDir: './.lighthouseci',
    },
  },
};
