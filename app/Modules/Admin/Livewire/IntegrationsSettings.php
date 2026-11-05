<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Theme\Chrome\SiteVerification;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Tích hợp & Theo dõi": the ownership codes a site gives Google Search Console, Bing Webmaster and
 * Facebook (site_settings group `storefront`, key `verification`). Each is one switch and one code;
 * only the code is stored, never markup, and a whole pasted meta tag is reduced to its content
 * value. The page edits a draft and saves it in one step; a code is checked when its field is left
 * and again on save. Writes are settings.manage-gated like every site-wide settings tab.
 */
#[Layout('admin::layouts.app')]
final class IntegrationsSettings extends Component
{
    /** @var array<string, bool> provider → switched on; changed only through toggle() */
    #[Locked]
    public array $on = [];

    /** @var array<string, string> provider → the code as typed */
    public array $tokens = [];

    #[Locked]
    public string $savedSig = '';

    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->load();
    }

    #[Authorize('settings.manage')]
    public function toggle(string $provider): void
    {
        if (isset(SiteVerification::PROVIDERS[$provider])) {
            $this->on[$provider] = ! ($this->on[$provider] ?? false);
            // Switching on re-checks the code already typed, so a stale field never shows as valid.
            $this->check($provider);
        }
    }

    /** A code was typed or pasted: reduce a pasted tag to its code, then check it. */
    public function updatedTokens(mixed $value, ?string $provider = null): void
    {
        if ($provider === null) {
            // The whole array was replaced: keep only known providers' string codes.
            $kept = [];
            foreach (array_keys(SiteVerification::PROVIDERS) as $known) {
                $kept[$known] = is_string($this->tokens[$known] ?? null) ? $this->tokens[$known] : '';
            }
            $this->tokens = $kept;

            return;
        }

        if (str_contains($provider, '.')) {
            // A nested key (tokens.google.x): reset the provider's code, drop an unknown one.
            $provider = strstr($provider, '.', true);
            if (isset(SiteVerification::PROVIDERS[$provider])) {
                $this->tokens[$provider] = '';
            } else {
                unset($this->tokens[$provider]);
            }

            return;
        }

        if (! isset(SiteVerification::PROVIDERS[$provider])) {
            unset($this->tokens[$provider]);

            return;
        }

        if (! is_string($value)) {
            $this->tokens[$provider] = '';

            return;
        }

        $typed = $value;
        if (SiteVerification::foreign($provider, $typed)) {
            $this->tokens[$provider] = $typed;   // what was pasted stays; the field says why it is refused
            $this->check($provider);

            return;
        }

        $code = SiteVerification::extract($typed);
        if ($code !== trim($typed)) {
            $this->notify(__('admin.int.detected', ['code' => $code]));
        }
        $this->tokens[$provider] = $code;
        $this->check($provider);
    }

    #[Authorize('settings.manage')]
    public function save(): void
    {
        $this->resetErrorBag();
        $bad = [];
        foreach (array_keys(SiteVerification::PROVIDERS) as $provider) {
            if (! $this->check($provider)) {
                $bad[] = $provider;
            }
        }

        if ($bad !== []) {
            $names = array_map(fn (string $p): string => __('admin.int.p.'.$p.'.name'), $bad);
            $this->notify(count($bad) > 1
                ? __('admin.int.toast_invalid_many', ['count' => count($bad), 'names' => implode(', ', $names)])
                : __('admin.int.toast_invalid_one', ['name' => $names[0]]), 'error');
            $this->dispatch('int-first-error');

            return;
        }

        app(SettingsRepository::class)->put('storefront', 'verification', $this->stored());
        $this->load();
        $this->notify(__('admin.int.toast_saved'));
    }

    /** Back to the saved setting. */
    #[Authorize('settings.manage')]
    public function resetChanges(): void
    {
        $this->load();
        $this->resetErrorBag();
        $this->notify(__('admin.int.toast_reset'));
    }

    #[Authorize('settings.manage')]
    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    public function render(): View
    {
        $view = view('admin::livewire.integrations-settings', [
            'dirty' => $this->signature() !== $this->savedSig,
        ]);
        $view->title(__('admin.int.title'));

        return $view;
    }

    /** Whether a provider's code is fine to save; a bad one on a switched-on provider gets a message on its field. */
    private function check(string $provider): bool
    {
        $raw = (string) ($this->tokens[$provider] ?? '');
        $code = SiteVerification::extract($raw);
        $this->resetErrorBag('tokens.'.$provider);

        if (($this->on[$provider] ?? false) && SiteVerification::foreign($provider, $raw)) {
            $this->addError('tokens.'.$provider, __('admin.int.wrong_tag', ['name' => __('admin.int.p.'.$provider.'.name')]));

            return false;
        }

        if (! ($this->on[$provider] ?? false) || $code === '' || SiteVerification::valid($provider, $code)) {
            return true;
        }

        $this->addError('tokens.'.$provider, __('admin.int.bad_format', [
            'field' => mb_strtolower(__('admin.int.p.'.$provider.'.field')),
            'example' => __('admin.int.p.'.$provider.'.ph'),
        ]));

        return false;
    }

    /**
     * What is saved: per provider the switch and the code. A code that is not well-formed is never
     * kept (it can only be left over on a switched-off provider).
     *
     * @return array<string, array{on: bool, token: string}>
     */
    private function stored(): array
    {
        $out = [];
        foreach (array_keys(SiteVerification::PROVIDERS) as $provider) {
            $raw = (string) ($this->tokens[$provider] ?? '');
            $code = SiteVerification::foreign($provider, $raw) ? '' : SiteVerification::extract($raw);
            $out[$provider] = [
                'on' => (bool) ($this->on[$provider] ?? false),
                'token' => SiteVerification::valid($provider, $code) ? $code : '',
            ];
        }

        return $out;
    }

    private function load(): void
    {
        $saved = app(SettingsRepository::class)->get('storefront', 'verification') ?? [];
        foreach (array_keys(SiteVerification::PROVIDERS) as $provider) {
            $row = $saved[$provider] ?? null;
            $row = is_array($row) ? $row : [];
            $token = is_string($row['token'] ?? null) ? $row['token'] : '';
            $this->on[$provider] = ($row['on'] ?? false) === true;
            $this->tokens[$provider] = SiteVerification::valid($provider, $token) ? $token : '';
        }
        $this->savedSig = $this->signature();
    }

    private function signature(): string
    {
        $draft = [];
        foreach (array_keys(SiteVerification::PROVIDERS) as $provider) {
            $draft[$provider] = [(bool) ($this->on[$provider] ?? false), SiteVerification::extract($this->tokens[$provider] ?? '')];
        }

        return md5((string) json_encode($draft));
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
