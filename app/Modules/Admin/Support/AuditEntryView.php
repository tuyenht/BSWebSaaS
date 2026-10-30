<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Registry\FeatureRegistry;

/**
 * Turns one audit row into what the Audit log screen says about it, in plain words: who did it
 * (and the support staff member behind a member's account), on which device, to what, and the
 * details worth reading. Raw codes and internal ids never come out of here: details are an
 * allow-list of known keys, each with its own label, so ids, ulids and hashes stored in `meta`
 * stay unseen.
 */
final readonly class AuditEntryView
{
    /** The keys that name what the action was done to, the first one present wins. */
    private const array TARGET_KEYS = ['hostname', 'name', 'member', 'role'];

    /** The keys shown under "Chi tiết", in this order. */
    private const array DETAIL_KEYS = ['reason', 'minutes', 'why', 'factor', 'hostname', 'role', 'name', 'member', 'was', 'value'];

    /** Values of `why` and `factor` that have words; any other value is left out. */
    private const array WORDED = [
        'why' => ['left', 'expired', 'replaced'],
        'factor' => ['email_otp', 'totp', 'recovery'],
    ];

    /**
     * @param  array<int, string>  $names  user id => name, for every actor and staff member on the page
     */
    public function __construct(private array $names, private string $brand) {}

    public function isSupport(AuditLog $row): bool
    {
        return $row->getAttribute('impersonator_id') !== null || str_starts_with($row->action, 'support.');
    }

    /** "Lê Hoàng Phúc (Bảo Sơn) thay mặt Nguyễn Ngọc Anh" during support, else the member alone. */
    public function who(AuditLog $row): string
    {
        $member = $this->person($row->getAttribute('actor_id'), $row->getAttribute('actor_label'));
        $staffId = $row->getAttribute('impersonator_id');
        if ($staffId === null) {
            return $member;
        }

        return __('admin.audit.on_behalf', [
            'staff' => $this->person($staffId, $row->getAttribute('impersonator_label')),
            'brand' => $this->brand,
            'member' => $member,
        ]);
    }

    /** The staff member behind a support row, or null. */
    public function staff(AuditLog $row): ?string
    {
        $staffId = $row->getAttribute('impersonator_id');

        return $staffId === null ? null : $this->person($staffId, $row->getAttribute('impersonator_label'));
    }

    public function member(AuditLog $row): string
    {
        return $this->person($row->getAttribute('actor_id'), $row->getAttribute('actor_label'));
    }

    /** "Chrome · Windows"; "Tự động" for work the system did by itself; "—" when nothing is known. */
    public function device(AuditLog $row): string
    {
        $agent = Cast::string($row->getAttribute('user_agent'));
        if ($agent === '') {
            return $this->isSystem($row) ? __('admin.audit.automatic') : '—';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh'), str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Ubuntu') => 'Ubuntu',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
        $known = array_values(array_filter([$browser, $system]));

        return $known === [] ? __('admin.audit.device_other') : implode(' · ', $known);
    }

    /** What the action was done to ("nhakhoatamduc.vn", a role's name, a plan feature…), or '' when the row does not say. */
    public function target(AuditLog $row): string
    {
        // a plan feature is stored by its key; only a known one is named, the key itself is never shown
        $feature = $row->meta['feature'] ?? null;
        if (is_string($feature) && FeatureRegistry::has($feature)) {
            return FeatureRegistry::all()[$feature]['name'];
        }

        foreach (self::TARGET_KEYS as $key) {
            $value = $row->meta[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function details(AuditLog $row): array
    {
        $meta = $row->meta;
        $targetKey = null;
        foreach (self::TARGET_KEYS as $key) {
            if (is_string($meta[$key] ?? null) && trim($meta[$key]) !== '') {
                $targetKey = $key;
                break;
            }
        }

        $details = [];
        foreach (self::DETAIL_KEYS as $key) {
            if ($key === $targetKey || ! array_key_exists($key, $meta)) {
                continue;
            }
            $value = $this->worded($key, $meta[$key]);
            if ($value !== null) {
                $details[] = ['label' => __('admin.audit.meta.'.$key), 'value' => $value];
            }
        }

        return $details;
    }

    private function worded(string $key, mixed $value): ?string
    {
        if (isset(self::WORDED[$key])) {
            return is_string($value) && in_array($value, self::WORDED[$key], true) ? Cast::string(__('admin.audit.meta.'.$key.'_'.$value)) : null;
        }

        return match (true) {
            $key === 'minutes' && is_int($value) => Cast::string(__('admin.audit.meta.minutes_value', ['n' => $value])),
            is_bool($value) => Cast::string(__($value ? 'admin.audit.meta.on' : 'admin.audit.meta.off')),
            is_string($value) && trim($value) !== '' => $value,
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    private function isSystem(AuditLog $row): bool
    {
        return $row->getAttribute('actor_id') === null
            && in_array($row->getAttribute('actor_label'), [null, '', 'console'], true);
    }

    private function person(mixed $id, mixed $label): string
    {
        $name = $id === null ? null : ($this->names[Cast::int($id)] ?? null);
        if ($name !== null && $name !== '') {
            return $name;
        }
        $label = Cast::string($label);

        return $label === '' || $label === 'console' ? __('admin.audit.system') : $label;
    }
}
