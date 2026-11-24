<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PreviewMode;
use App\Modules\Tenancy\Context\ResolvedHost;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A website's extra embed code (Cài đặt › Bảo mật & Nâng cao, «Mã nhúng thêm»): code its owner
 * pasted from a provider, printed raw in four places of every content page. Stored in site_settings
 * group `storefront`, key `embed_code`:
 *
 *   {"on": bool, "code": {"head": "…", "body_top": "…", "body_end": "…", "foot": "…"},
 *    "acknowledged_at": "<UTC ISO 8601>"|null, "acknowledged_by": <user id>|null}
 *
 * The code runs only where it cannot reach a signed-in session: the admin lives on the same host
 * (/admin), so a request that carries a session (a signed-in member, a visitor whose form was just
 * sent: StorefrontSession) gets none of it, and neither does a preview, a request with no resolved
 * website host (a job, a command) or a page the layout does not print it on (the 404, the website's
 * state pages, maintenance). Switched off, the code is kept but never printed: the way back when a
 * pasted code breaks the website.
 *
 * Read through the storefront's one settings read (StorefrontSettings): a page pays no query for it.
 * Registered scoped: take() remembers that this response printed code, which is what loosens the
 * page's Content-Security-Policy (StorefrontCsp::withEmbedCode(), AddSecurityHeaders). A response
 * that printed none keeps the strict policy.
 */
final class SiteEmbedCode
{
    public const string GROUP = 'storefront';

    public const string KEY = 'embed_code';

    /** The four places, in page order: in <head>, right after <body>, after the main content, last before </body>. */
    public const array SLOTS = ['head', 'body_top', 'body_end', 'foot'];

    /**
     * Longest code kept per place, in bytes. Real snippets (Tag Manager, a pixel, a chat widget) are
     * 1–5 KB; four full boxes, escaped twice in the admin's Livewire request, stay under its 1 MB
     * payload limit.
     */
    public const int MAX_BYTES = 32768;

    private bool $printed = false;

    /** @var array<string, string>|null the code of each place for this response, once decided */
    private ?array $forPage = null;

    public function __construct(private readonly StorefrontSettings $settings) {}

    /**
     * The stored value in its one shape: unknown keys dropped, each place a string (over-long code
     * from an older write is dropped, never cut: half a script breaks the page).
     *
     * @return array{on: bool, code: array{head: string, body_top: string, body_end: string, foot: string}, acknowledged_at: CarbonImmutable|null, acknowledged_by: int|null}
     */
    public static function shape(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $given = is_array($stored['code'] ?? null) ? $stored['code'] : [];

        $code = ['head' => '', 'body_top' => '', 'body_end' => '', 'foot' => ''];
        foreach (self::SLOTS as $slot) {
            $text = $given[$slot] ?? null;
            if (is_string($text) && trim($text) !== '' && strlen($text) <= self::MAX_BYTES) {
                $code[$slot] = $text;
            }
        }

        $at = null;
        if (is_string($stored['acknowledged_at'] ?? null) && $stored['acknowledged_at'] !== '') {
            try {
                $at = CarbonImmutable::parse($stored['acknowledged_at'])->utc();
            } catch (Throwable) {
                $at = null;
            }
        }
        $by = $stored['acknowledged_by'] ?? null;

        return [
            'on' => ($stored['on'] ?? false) === true,
            'code' => $code,
            'acknowledged_at' => $at,
            'acknowledged_by' => is_int($by) ? $by : null,
        ];
    }

    /** @param array<string, string> $code */
    public static function hasCode(array $code): bool
    {
        foreach (self::SLOTS as $slot) {
            if (trim($code[$slot] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The code of one place for this response, or '' — and, when there is some, remember that this
     * response carries code (its CSP is loosened). The layout calls it where the place is.
     */
    public function take(string $slot): string
    {
        $code = ($this->forPage ??= $this->decide())[$slot] ?? '';
        if ($code !== '') {
            $this->printed = true;
        }

        return $code;
    }

    /** Whether this response printed any embed code. */
    public function printed(): bool
    {
        return $this->printed;
    }

    /**
     * The code of each place for this response: none unless this is a website's public page, for a
     * visitor with no session, outside a preview, with the switch on.
     *
     * @return array<string, string>
     */
    private function decide(): array
    {
        $request = request();
        // the cookie as well as the session: a request that brings a session cookie is a member's or
        // a form sender's even where no session was started for it
        if ($request->hasSession()
            || $request->cookies->has(Cast::string(config('session.cookie')))
            || PreviewMode::on()
            || ! $request->attributes->get(ResolvedHost::class) instanceof ResolvedHost
            || ! $request->isMethodCacheable()) {
            return [];
        }

        $shape = self::shape($this->settings->get(self::GROUP, self::KEY));

        return $shape['on'] ? $shape['code'] : [];
    }
}
