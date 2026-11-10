<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Forms\Actions\SubmitForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Shared\Contracts\PageLinks;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Storefront\Http\Requests\StoreLeadRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public storefront lead capture: POST /forms/{key}. CSRF-exempt (see bootstrap/app.php) — a
 * session-less public endpoint defended by Turnstile + honeypot + rate-limit (§23/R20). The form
 * is resolved by key within the host's site context (RLS, fail-closed) once, by the request that
 * validated it, the lead is captured via
 * SubmitForm, and the visitor is redirected back to the page with a localized confirmation — or,
 * when the form names a thank-you page (`settings.redirect_entry`, the page's ulid) that a visitor
 * can open on this site in their language, sent there with a 303. Too many attempts from one IP
 * are throttled with a plain-language message (no 429 dead end) and never leave the page.
 */
final readonly class FormSubmissionController
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function __construct(private SubmitForm $submit, private PageLinks $pages) {}

    public function __invoke(StoreLeadRequest $request, string $key): RedirectResponse
    {
        $form = $request->leadForm();
        abort_if($form === null, 404);

        $throttle = 'lead-submit:'.$form->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttle, self::MAX_ATTEMPTS)) {
            return back()->withInput()->withErrors([
                'form' => __('forms.rate_limited', ['seconds' => RateLimiter::availableIn($throttle)]),
            ]);
        }
        RateLimiter::hit($throttle, self::DECAY_SECONDS);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->submit->handle(
            $form,
            $data,
            honeypot: $request->string('company_website')->toString(),
            turnstileToken: $request->string('cf-turnstile-response')->toString(),
            meta: [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source_url' => $request->headers->get('referer'),
            ],
        );

        $thankYou = $this->thankYouPath($form);
        if ($thankYou !== null) {
            return redirect()->to($thankYou, 303);
        }

        $confirmation = $form->successMessageFor(app()->getLocale()) ?? (string) __('forms.submit_success');

        return back()->with('form_success', $confirmation)->with('form_success_key', $form->key);
    }

    /**
     * The thank-you page's path in the visitor's language: only a ulid that PageLinks resolves to a
     * published page of this site (another site's ulid, a draft, a trashed page or any other value
     * resolves to nothing), and only a path on this site — never an absolute or `//host` link.
     */
    private function thankYouPath(Form $form): ?string
    {
        $ulid = Cast::arr($form->settings)['redirect_entry'] ?? null;
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }
        $path = $this->pages->paths([$ulid], app()->getLocale())[$ulid] ?? null;

        return SafeLink::isSitePath($path) ? $path : null;
    }
}
