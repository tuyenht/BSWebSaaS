<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Http\Request;

/**
 * Whether the page being drawn is the page builder's preview frame: the real storefront page of an
 * entry drawn from the editor's unsaved blocks (admin.entries.builder-preview). Only then do the
 * blocks of the page's own body carry their place in the editor's list (`data-pb-i`), a shared block
 * that shows nothing leaves an empty mark where it stands, and the head names the frame's nonce
 * (`<meta name="bs-pb">`), which the frame's script repeats in every message to the editor. A
 * visitor's page never carries any of it. Always together with PreviewMode (forms off, no view
 * counted, never cached). Kept on the request itself, so it never outlives the request under Octane.
 */
final class BuilderPreview
{
    /** The key a body block carries its place in the editor's list under, set only by ReusableBlockExpander. */
    public const string INDEX = '_pb_i';

    /**
     * The type of the empty mark a shared block that shows nothing leaves (never a registered block
     * type); its `state` says why: «draft» (still a draft) or «gone» (deleted, or nothing in any language).
     */
    public const string EMPTY_TYPE = '_pb_empty';

    private const string KEY = 'storefront.builder-preview';

    /** 128 random bits as 32 lower-case hex digits: the only nonce ever printed into a page. */
    private const string NONCE = '/^[0-9a-f]{32}$/';

    public static function enable(Request $request, string $nonce): void
    {
        if (self::nonceOf($nonce) === null) {
            throw new \InvalidArgumentException('A builder preview needs a well-formed nonce.');
        }
        $request->attributes->set(self::KEY, $nonce);
    }

    public static function on(): bool
    {
        return self::nonce() !== null;
    }

    /** The nonce of this preview, or null when the page is not one. */
    public static function nonce(): ?string
    {
        return self::nonceOf(request()->attributes->get(self::KEY));
    }

    /** $value when it is a well-formed nonce, else null: a string the client sent is printed only once it passed this. */
    public static function nonceOf(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::NONCE, $value) === 1 ? $value : null;
    }
}
