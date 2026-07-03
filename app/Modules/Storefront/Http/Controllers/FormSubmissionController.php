<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Forms\Actions\SubmitForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Storefront\Http\Requests\StoreLeadRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public storefront lead capture: POST /forms/{key}. CSRF-exempt (see bootstrap/app.php) — a
 * session-less public endpoint defended by Turnstile + honeypot + rate-limit (§23/R20). The form
 * is resolved by key within the host's site context (RLS, fail-closed), the lead is captured via
 * SubmitForm, and the visitor is redirected back to the page with a localized confirmation. Too
 * many attempts from one IP are throttled with a plain-language message (no 429 dead end).
 */
final readonly class FormSubmissionController
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function __construct(private SubmitForm $submit) {}

    public function __invoke(StoreLeadRequest $request, string $key): RedirectResponse
    {
        $form = Form::query()->where('key', $key)->where('status', 'active')->first();
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

        $confirmation = $form->successMessageFor(app()->getLocale()) ?? (string) __('forms.submit_success');

        return back()->with('form_success', $confirmation);
    }
}
