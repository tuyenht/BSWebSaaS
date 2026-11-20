<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Forms;

use App\Modules\Admin\Livewire\Forms\Concerns\GatesFormScreens;
use App\Modules\Cms\Routing\FormPagePaths;
use App\Modules\Forms\Actions\DeleteForm;
use App\Modules\Forms\Actions\SetFormStatus;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Forms\Support\FormTemplates;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Biểu mẫu» (board 15): the site's forms — the on/off switch beside each name, how many leads each
 * took, where its lead emails go — and «Thêm biểu mẫu», which asks what the form is for and opens
 * the editor. A site holds few forms, so every form is read once (up to MAX_FORMS) and the groups,
 * the search (which ignores Vietnamese marks) and the leads filter work on that list.
 *
 * Gated like the leads inbox: the plan (the `contacts` module) first, then `forms.manage`; every
 * write asks FormPolicy again, as the livewire/update endpoint skips the route. Support staff signed
 * in for a member never delete a form: its leads are visitors' personal data and go with it.
 */
#[Layout('admin::layouts.app')]
final class FormList extends Component
{
    use GatesFormScreens;

    /** @var list<string> the status groups */
    public const array TABS = ['all', 'on', 'off'];

    /** @var list<string> the «Liên hệ đã nhận» choices */
    public const array HAS = ['yes', 'no'];

    /** Leads are counted up to this many per form; above it the list says "hơn 10.000". */
    public const int LEAD_CAP = 10000;

    #[Url(as: 'status', except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> */
    #[Url(as: 'leads', except: [])]
    public array $has = [];

    public string $toastMsg = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /** @var array{ulid: string, active: bool}|null what «Hoàn tác» puts back: the form and its state before */
    #[Locked]
    public ?array $undoOp = null;

    /** The «Xóa» question is open (entangled with the dialog). */
    public bool $confirmOpen = false;

    /** @var array{ulid: string, name: string, leads: int, active: bool}|null the form «Xóa» asks about, read when it opened */
    #[Locked]
    public ?array $deleting = null;

    /** The row's switch: on takes leads at once, off refuses new ones; «Hoàn tác» puts it back. */
    public function setActive(string $ulid, bool $active): void
    {
        $form = $this->writable($ulid);
        if ($form === null) {
            return;
        }
        $before = $form->status === 'active';
        if (! app(SetFormStatus::class)->handle($form, $active)) {
            $this->toast(__('form_builder.toast_gone'), 'error');

            return;
        }
        $name = $this->nameOf($form);
        $this->toast(__($active ? 'form_builder.toast_on' : 'form_builder.toast_off', ['name' => $name]), undo: $before !== $active ? ['ulid' => $form->ulid, 'active' => $before] : null);
    }

    /**
     * «Xóa» in the row's ⋮: the question names the form and how many leads go with it, and offers
     * «Tắt biểu mẫu» instead while the form is on and has leads. The system form has no «Xóa».
     * Support staff signed in for a member never delete one on their behalf (the member does it).
     */
    public function askDelete(string $ulid): void
    {
        $form = $this->writable($ulid, 'delete');
        if ($form === null) {
            return;
        }
        app(SupportSession::class)->forbid();
        $this->deleting = [
            'ulid' => $form->ulid,
            'name' => $this->nameOf($form),
            'leads' => $this->leadCounts([$form->id])[$form->id] ?? 0,
            'active' => $form->status === 'active',
        ];
        $this->confirmOpen = true;
    }

    /** The dialog's red button: the form, its fields and its leads go for good (no Trash, no undo). */
    public function confirmDelete(): void
    {
        app(SupportSession::class)->forbid();
        $asked = $this->deleting;
        $this->cancelDelete();
        $form = $asked === null ? null : $this->writable($asked['ulid'], 'delete');
        if ($asked === null || $form === null) {
            return;
        }
        // "hơn 10.000" was shown: any number above it is what the member agreed to
        $shown = $asked['leads'] > self::LEAD_CAP ? null : $asked['leads'];
        $done = app(DeleteForm::class)->handle($form, $shown);
        if ($done === null) {
            $this->toast(__('form_builder.toast_gone'), 'error');

            return;
        }
        if (! $done['deleted']) {
            // leads came in after the question: ask again with the count as it is now
            $this->deleting = [...$asked, 'leads' => min($done['leads'], self::LEAD_CAP + 1)];
            $this->confirmOpen = true;
            $this->toast(__('form_builder.toast_more_leads'), 'info');

            return;
        }
        if ($done['later']) {
            // a form with this many leads is emptied in batches off the request: it is off and hidden at once
            $this->toast(__('form_builder.toast_deleting', ['leads' => $asked['leads'] > self::LEAD_CAP
                ? __('form_builder.leads_over', ['n' => $this->num(self::LEAD_CAP)])
                : trans_choice('form_builder.leads_n', $asked['leads'], ['n' => $this->num($asked['leads'])])]));

            return;
        }
        $leads = $done['leads'];
        $this->toast($leads > 0
            ? __('form_builder.toast_deleted_leads', ['leads' => trans_choice('form_builder.leads_n', $leads, ['n' => $this->num($leads)])])
            : __('form_builder.toast_deleted'));
    }

    public function cancelDelete(): void
    {
        $this->confirmOpen = false;
        $this->deleting = null;
    }

    /** The dialog's «Tắt biểu mẫu»: the form stops taking leads and keeps those it has, with «Hoàn tác». */
    public function switchOffInstead(): void
    {
        $ulid = $this->deleting['ulid'] ?? null;
        $this->cancelDelete();
        if ($ulid !== null) {
            $this->setActive($ulid, false);
        }
    }

    public function undoLast(): void
    {
        $op = $this->undoOp;
        $this->undoOp = null;
        $this->toastMsg = '';
        if ($op === null) {
            return;
        }
        $form = $this->writable($op['ulid']);
        if ($form !== null && ! app(SetFormStatus::class)->handle($form, $op['active'])) {
            $this->toast(__('form_builder.toast_gone'), 'error');
        }
    }

    public function closeToast(int $seq = 0): void
    {
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toastMsg = '';
            $this->undoOp = null;
        }
    }

    public function clearFilters(): void
    {
        $this->tab = 'all';
        $this->search = '';
        $this->has = [];
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function removeHas(string $value): void
    {
        $this->has = array_values(array_diff($this->has, [$value]));
    }

    public function render(): View
    {
        // The plan first, then the permission: either card, and no form is read.
        if (($locked = $this->lockedView()) !== null) {
            return $this->titled($locked);
        }

        $this->clean();
        $locale = app(SiteLocale::class)->default();

        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the page
            $rows = DB::transaction(function () use ($locale): array {
                $forms = Form::query()->select(['id', 'ulid', 'key', 'name', 'notify_emails', 'status'])->orderBy('id')->limit(FormDraft::MAX_FORMS)->get();
                $ids = array_values(array_map(fn (mixed $id): int => Cast::int($id), $forms->pluck('id')->all()));
                $counts = $this->leadCounts($ids);
                // one page a visitor sees each form on («Xem trang có biểu mẫu»), from form_placements
                $pages = app(FormPagePaths::class)->of($ids, $locale);

                return array_values($forms->map(fn (Form $form): array => [
                    'ulid' => $form->ulid,
                    'name' => FormDraft::text($form->name, $locale),
                    'system' => $form->isSystem(),
                    'active' => $form->status === 'active',
                    'leads' => $counts[$form->id] ?? 0,
                    'emails' => Cast::strings($form->notify_emails),
                    'page' => $pages[$form->id] ?? null,
                ])->all());
            });
            $failed = false;
        } catch (QueryException $e) {
            report($e);
            $rows = [];
            $failed = true;
        }

        $searched = array_values(array_filter($rows, fn (array $row): bool => $this->found($row)));
        $narrowed = array_values(array_filter($searched, fn (array $row): bool => $this->hasLeads($row)));
        $inTab = fn (array $row): bool => match ($this->tab) {
            'on' => $row['active'] === true,
            'off' => $row['active'] !== true,
            default => true,
        };
        $tabCounts = [
            'all' => count($narrowed),
            'on' => count(array_filter($narrowed, fn (array $r): bool => $r['active'])),
            'off' => count(array_filter($narrowed, fn (array $r): bool => ! $r['active'])),
        ];
        $hasCounts = [
            'yes' => count(array_filter($searched, fn (array $r): bool => $inTab($r) && $r['leads'] > 0)),
            'no' => count(array_filter($searched, fn (array $r): bool => $inTab($r) && $r['leads'] === 0)),
        ];

        return $this->titled(view('admin::livewire.forms.builder', [
            'failed' => $failed,
            'rows' => array_values(array_filter($narrowed, $inTab)),
            'total' => count(array_filter($rows, $inTab)),
            'any' => $rows !== [],
            'tabCounts' => $tabCounts,
            'hasCounts' => $hasCounts,
            'purposes' => $this->purposes($locale),
            'cap' => self::LEAD_CAP,
            // support staff signed in for a member: «Xóa» stays in the ⋮, with why it cannot be used
            'support' => app(SupportSession::class)->active(),
        ]));
    }

    /**
     * The picker's starting points: name, its sentence, its icon and how many fields it brings
     * (the consent box included).
     *
     * @return list<array{key: string, label: string, desc: string, icon: string, n: int, href: string}>
     */
    private function purposes(string $locale): array
    {
        return array_map(fn (string $p): array => [
            'key' => $p,
            'label' => __('form_builder.purpose.'.$p),
            'desc' => __('form_builder.purpose_desc.'.$p),
            'icon' => FormTemplates::ICONS[$p],
            'n' => count(FormTemplates::fields($p, $locale)) + 1,
            'href' => route('admin.form-builder.create', ['purpose' => $p]),
        ], FormTemplates::PURPOSES);
    }

    /**
     * The leads each form took, spam left out, counted up to LEAD_CAP + 1 in one statement (the
     * (site_id, form_id, created_at) index answers each).
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function leadCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $site = app(CurrentSite::class)->id();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            'select f.id, (select count(*) from (select 1 from form_submissions s where s.site_id = ? and s.form_id = f.id and s.status <> \'spam\' limit '.(self::LEAD_CAP + 1).') x) as n
             from forms f where f.site_id = ? and f.id in ('.$marks.')',
            [$site, $site, ...$ids],
        );
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[Cast::int($row['id'])] = Cast::int($row['n']);
        }

        return $out;
    }

    /** @param  array{name: string}  $row */
    private function found(array $row): bool
    {
        $q = $this->fold(trim($this->search));

        return $q === '' || str_contains($this->fold($row['name']), $q);
    }

    /** @param  array{leads: int}  $row */
    private function hasLeads(array $row): bool
    {
        if (count($this->has) === 1) {
            return $this->has[0] === 'yes' ? $row['leads'] > 0 : $row['leads'] === 0;
        }

        return true;
    }

    /** Lowercase without Vietnamese marks: "Báo giá" finds "bao gia". */
    private function fold(string $text): string
    {
        return Str::lower(Str::ascii(str_replace(['đ', 'Đ'], 'd', $text)));
    }

    /** Keep only values the screen knows: a pasted address with anything else falls back to the default. */
    private function clean(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'all';
        }
        $this->search = Str::limit($this->search, 100, '');
        $this->has = array_values(array_intersect(self::HAS, array_map('strval', $this->has)));
    }

    /** The form behind $ulid when the member may do $ability to it; else a toast says why, and null. */
    private function writable(string $ulid, string $ability = 'update'): ?Form
    {
        $form = Form::query()->where('ulid', $ulid)->first();
        if ($form === null) {
            // gone meanwhile (or never this site's: another site's form reads as gone, never as "not allowed")
            $this->toast(__('form_builder.toast_gone'), 'error');

            return null;
        }
        // the system contact form is never deleted, also by a platform operator, who passes every policy
        if (! $this->entitled() || Gate::denies($ability, $form) || ($ability === 'delete' && $form->key === Form::SYSTEM_KEY)) {
            $this->toast(__('form_builder.toast_cannot'), 'error');

            return null;
        }

        return $form;
    }

    private function num(int $n): string
    {
        return (string) Number::format($n, locale: app()->getLocale());
    }

    private function nameOf(Form $form): string
    {
        return FormDraft::text($form->name, app(SiteLocale::class)->default());
    }

    /** @param  array{ulid: string, active: bool}|null  $undo */
    private function toast(string $message, string $tone = 'success', ?array $undo = null): void
    {
        $this->toastMsg = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
        $this->undoOp = $undo;
    }

    private function titled(View $view): View
    {
        $view->title(__('form_builder.title'));

        return $view;
    }
}
