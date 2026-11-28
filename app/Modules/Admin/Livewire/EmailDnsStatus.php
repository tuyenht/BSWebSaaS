<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\Mail\MailDnsCheck;
use App\Modules\Admin\Support\Mail\MailDnsProviders;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * «Nâng cao — các dòng xác thực tên miền» under «Chọn dịch vụ» of the Email screen: the SPF, DKIM and
 * DMARC rows of the website's sending domain, as the last check (MailDnsCheck) found them. Its own
 * component, so while a check is under way or re-checking it polls with one settings read, never the
 * whole settings form.
 *
 * The Email screen passes the setup it shows ($domain through $provider); a check kept for another
 * domain or service (the form was changed and not checked yet) shows the rows as not checked. The
 * check itself starts from «Kiểm tra & lưu kết nối» (EmailSettings::checkRelay), which tells this
 * box with `em-dns-started`, or `em-dns-refused` when the press limit stopped it.
 */
final class EmailDnsStatus extends Component
{
    public const array ROWS = ['spf', 'dkim', 'dmarc'];

    /** The domain the Email screen's setup sends from ('' = none yet). */
    #[Reactive]
    public string $domain = '';

    /** Its service, as MailDnsProviders::of() names it. */
    #[Reactive]
    public string $provider = 'smtp';

    /** The last press was refused by the limit: the box says how long to wait (read from the limiter). */
    public bool $refused = false;

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: the Email screen's own checks again.
        abort_unless(Gate::allows('settings.manage'), 403);
        app(SupportSession::class)->forbid();
    }

    #[On('em-dns-started')]
    public function started(): void
    {
        $this->refused = false;
    }

    #[On('em-dns-refused')]
    public function wasRefused(): void
    {
        $this->refused = true;
    }

    public function render(): View
    {
        $provider = array_key_exists($this->provider, MailDnsProviders::TABLE) ? $this->provider : 'smtp';
        $domain = MailDnsProviders::domain($this->domain);
        $state = app(MailDnsCheck::class)->state($domain, $provider);
        $phase = $state['phase'] ?? 'idle';
        $name = $this->providerName($provider);

        $rows = [];
        $stopped = false;
        foreach (self::ROWS as $row) {
            $found = $phase === 'running' ? null : ($state['rows'][$row] ?? null);
            $code = $found['code'] ?? null;
            $tone = match (true) {
                $code === 'ok' => 'ok',
                $code === 'unchecked' => 'unchecked',
                $code !== null && $phase === 'auto' => 'pending',
                $code !== null => 'failed',
                default => 'idle',
            };
            $stopped = $stopped || $tone === 'failed';
            $rows[] = [
                'key' => $row,
                'name' => strtoupper($row),
                'desc' => __('admin.em_dns_'.$row.'_desc'),
                'tone' => $tone,
                'state' => __('admin.em_dns_state_'.$tone),
                'note' => $found === null ? '' : $this->note($row, $found, $name, $domain, $provider),
            ];
        }

        $hint = match ($phase) {
            'running' => __('admin.em_dns_hint_running'),
            'auto' => __('admin.em_dns_hint_auto'),
            'done' => $stopped
                ? __('admin.em_dns_hint_stopped')
                : __('admin.em_dns_hint_done', ['time' => $this->when($state['checked_at'] ?? null)]),
            default => __('admin.em_dns_hint_idle'),
        };

        $current = app(CurrentSite::class);
        $wait = $this->refused && $current->hasId() ? MailDnsCheck::pressWait($current->id()) : 0;

        return view('admin::livewire.email-dns-status', [
            'rows' => $rows,
            'hint' => $hint,
            'phase' => $phase,
            'wait' => $wait > 0 ? __($phase === 'auto' ? 'admin.em_dns_wait_auto' : 'admin.em_dns_wait', ['minutes' => (int) ceil($wait / 60)]) : '',
        ]);
    }

    /**
     * What a row says under its description: how to fix a record that is missing or wrong, or why it
     * could not be checked; '' when it passed.
     *
     * @param  array{code: string, why?: string, host?: string}  $found
     */
    private function note(string $row, array $found, string $name, string $domain, string $provider): string
    {
        $host = $found['host'] ?? '';
        $include = MailDnsProviders::TABLE[$provider]['spf']['include'] ?? '';

        return Cast::string(match ($found['code']) {
            'unchecked' => __('admin.em_dns_why_'.($found['why'] ?? 'unknown_server'), ['name' => $name, 'domain' => $domain]),
            'missing' => match ($row) {
                'spf' => __('admin.em_dns_fix_spf_missing', ['host' => $host, 'value' => 'v=spf1 include:'.$include.' ~all']),
                'dkim' => __('admin.em_dns_fix_dkim_missing', ['host' => $host, 'name' => $name]),
                default => __('admin.em_dns_fix_dmarc_missing', ['host' => $host, 'value' => 'v=DMARC1; p=none']),
            },
            'not_allowed' => __('admin.em_dns_fix_spf_not_allowed', ['host' => $host, 'name' => $name, 'include' => 'include:'.$include]),
            'multiple' => __('admin.em_dns_fix_multiple', ['host' => $host, 'record' => strtoupper($row)]),
            default => '',
        });
    }

    private function providerName(string $provider): string
    {
        return Cast::string(__(in_array($provider, ['google', 'microsoft', 'smtp'], true) ? 'admin.em_dns_name_'.$provider : 'admin.em_rl_'.$provider));
    }

    private function when(mixed $at): string
    {
        if (! $at instanceof DateTimeInterface) {
            return '';
        }

        return CarbonImmutable::instance($at)->setTimezone(app(SiteTime::class)->now()->getTimezone())->format('H:i · d/m/Y');
    }
}
