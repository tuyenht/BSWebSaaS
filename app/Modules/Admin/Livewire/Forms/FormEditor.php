<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Forms;

use App\Modules\Admin\Livewire\Forms\Concerns\GatesFormScreens;
use App\Modules\Admin\Support\LangBar;
use App\Modules\Cms\Routing\FormPagePaths;
use App\Modules\Forms\Actions\SaveForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Forms\Support\FormLangStates;
use App\Modules\Forms\Support\FormTemplates;
use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Chrome\StorefrontChrome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The «Biểu mẫu» editor (board 15): a form's name and switch, its fields (seven kinds; dragged or
 * moved up and down, one opened at a time), the thank-you sentence and the addresses its lead emails
 * go to, with the visitor's view beside them. The draft lives here until «Lưu»: the bar counts the
 * changes the board's way (each of name, thanks, emails and switch is one; each field changed, added
 * or removed is one; a new order is one), fields are checked as they are left, and leaving with
 * changes asks first. The consent box is the platform's: shown locked, last, never part of the draft.
 *
 * A saved form on a site with several languages opens in one of them (`?locale=`, else the main one)
 * under the shared language bar: in the main language everything is edited (SaveForm::handle, the
 * other languages kept as they are); in another one only its texts — name, labels, choices, thanks —
 * with the main language's words as the examples, and what every language shares (switch, emails,
 * fields, their kinds, order and settings) shown locked with «Chung» (SaveForm::translate).
 * A file field («Tệp đính kèm») picks one of the platform's kinds of file and how many (1 to 5);
 * the kind is offered only while the platform takes it (forms.file_kinds), and the type not at all
 * when it takes none. A field keeps a kind the platform has since held back, shown as picked.
 *
 * @phpstan-import-type Draft from FormDraft
 * @phpstan-import-type DraftField from FormDraft
 */
#[Layout('admin::layouts.app')]
final class FormEditor extends Component
{
    use GatesFormScreens;

    /** The kinds of field a member can add, in the board's order, with their icons. */
    public const array KINDS = ['text' => 'ri-text', 'email' => 'ri-mail', 'tel' => 'ri-phone', 'textarea' => 'ri-file-text', 'select' => 'ri-list-check', 'checkbox' => 'ri-checkbox', FormFieldTypes::FILE => 'ri-attachment-2'];

    #[Locked]
    public ?string $ulid = null;

    /** The language whose texts are edited: `?locale=` when the site has it enabled, else its main one; never client-set. */
    #[Locked]
    public string $locale = 'vi';

    /** The site's main language: the source the other languages are written from. */
    #[Locked]
    public string $source = 'vi';

    /** Whether the site writes in more than one language (the fields' language chips). */
    #[Locked]
    public bool $multiLang = false;

    /**
     * The main language's texts, the examples and fallbacks while another language is open.
     *
     * @var array{name: string, success: string, labels: array<string, string>, choices: array<string, string>}
     */
    #[Locked]
    public array $sourceTexts = ['name' => '', 'success' => '', 'labels' => [], 'choices' => []];

    /**
     * Each choice field's choice values, in the order its texts were read: another language's lines
     * belong to these choices (line n to the n-th value), even when the main language reorders or
     * removes choices before this language is saved.
     *
     * @var array<string, list<string>>
     */
    #[Locked]
    public array $choiceValues = [];

    /**
     * Beside each of those values, the main language's label it had then: a value the main language
     * retired and a later choice took again is not the choice these lines were written for.
     *
     * @var array<string, list<string>>
     */
    #[Locked]
    public array $choiceSources = [];

    /**
     * The language bar of a saved form on a site with more than one language (LangBar::items()).
     *
     * @var list<array{code: string, label: string, kind: string, done: int, total: int, href: string, current: bool, source: bool, public: bool, live: bool}>
     */
    #[Locked]
    public array $langBar = [];

    #[Locked]
    public bool $isNew = false;

    #[Locked]
    public string $consentLabel = '';

    /** The public path of one page showing this form («Đang có trên trang»), read once when the editor opens. */
    #[Locked]
    public ?string $pagePath = null;

    public string $name = '';

    public string $success = '';

    public bool $active = true;

    /** @var list<DraftField> */
    public array $fields = [];

    /** @var list<string> */
    #[Locked]
    public array $emails = [];

    public string $newEmail = '';

    #[Locked]
    public string $emailError = '';

    /** The one field opened for editing (its key). */
    public ?string $openKey = null;

    /** The preview shows the thank-you sentence instead of the form. */
    public bool $showThanks = false;

    /** @var Draft the form as last saved (a new form: as its starting point) */
    #[Locked]
    public array $saved = ['name' => '', 'success' => '', 'active' => true, 'emails' => [], 'fields' => []];

    /** @var array<string, true> what the member has left or tried to save: 'name', 'success', 'f:<key>' */
    #[Locked]
    public array $touched = [];

    #[Locked]
    public ?string $savedAt = null;

    public string $toastMsg = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /** @var array{field: DraftField, index: int}|null the field «Hoàn tác» puts back */
    #[Locked]
    public ?array $removed = null;

    public function mount(?string $ulid = null, ?string $purpose = null, ?string $locale = null): void
    {
        $languages = app(SiteLocale::class);
        $this->source = $languages->default();
        $this->multiLang = count($languages->enabled()) > 1;
        $requested = $locale ?? request()->query('locale');
        $this->locale = $ulid !== null && is_string($requested) && in_array($requested, $languages->enabled(), true) ? $requested : $this->source;
        if (! $this->entitled() || ! Gate::allows('viewAny', Form::class)) {
            return; // render() shows the plan or the no-permission card; nothing is read
        }

        if ($ulid !== null) {
            $form = Form::query()->where('ulid', $ulid)->with('fields')->firstOrFail();
            $this->ulid = $form->ulid;
            $draft = $this->translating() ? FormDraft::translation($form, $this->locale, $this->source) : FormDraft::fromForm($form, $this->locale);
            $this->readSourceTexts($form);
            $this->readLangBar($form);
            $consent = $form->fields->firstWhere('key', Form::CONSENT_KEY);
            $this->consentLabel = $consent !== null ? FormDraft::text($consent->label, $this->locale) : '';
            $this->pagePath = app(FormPagePaths::class)->of([$form->id], $this->locale)[$form->id] ?? null;
        } else {
            abort_unless(in_array($purpose, FormTemplates::PURPOSES, true), 404);
            $this->isNew = true;
            $draft = FormDraft::template($purpose, $this->locale);
            $this->sourceTexts = ['name' => $draft['name'], 'success' => '', 'labels' => array_column($draft['fields'], 'label', 'key'), 'choices' => array_column($draft['fields'], 'choices', 'key')];
        }
        if ($this->consentLabel === '') {
            $this->consentLabel = (string) __('forms.consent_label', [], $this->locale);
        }
        $this->fill($this->draftProps($draft));
        $this->saved = $draft; // first as read, so a field keeps a kind the platform has since held back
        $this->saved = $this->draft(); // compared the way the draft is, so nothing counts as changed on opening
    }

    /** Only the draft's own fields may be set from the page; every other change goes through a method. */
    public function updating(string $property, mixed $value): void
    {
        // in another language than the main one only its texts change; what every language shares is locked
        $shared = $this->translating() && ($property === 'newEmail' || $property === 'active'
            || preg_match('/^fields\.\d+\.(type|required|accept|max_files)$/', $property) === 1);
        $scalar = $shared ? false : match ($property) {
            'name', 'success', 'newEmail' => is_string($value),
            'active', 'showThanks' => is_bool($value),
            'openKey' => $value === null || is_string($value),
            default => null,
        };
        if (! $shared && $scalar === null && preg_match('/^fields\.(\d+)\.(label|type|required|choices|accept|max_files)$/', $property, $m) === 1) {
            // only a row the draft has, with a value of the column's own kind
            $scalar = isset($this->fields[(int) $m[1]]) && match ($m[2]) {
                'required' => is_bool($value),
                'type' => is_string($value) && array_key_exists($value, $this->kinds()),
                'accept' => is_string($value) && (in_array($value, FormFieldTypes::fileKinds(), true) || $value === ($this->savedKind($this->fields[(int) $m[1]]['key']) ?? '')),
                'max_files' => (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 1 && (int) $value <= FormFieldTypes::MAX_FILES,
                default => is_string($value),
            };
        }
        if ($scalar !== true) {
            throw new AuthorizationException;
        }
    }

    /** A field the member leaves is checked from then on (its message stays until it is right). */
    public function updated(string $property): void
    {
        if ($property === 'name' || $property === 'success') {
            $this->touched[$property] = true;
        } elseif (preg_match('/^fields\.(\d+)\.(label|choices)$/', $property, $m) === 1 && isset($this->fields[(int) $m[1]])) {
            $this->touched['f:'.$this->fields[(int) $m[1]]['key']] = true;
        } elseif ($property === 'newEmail') {
            $this->emailError = '';
        } elseif (preg_match('/^fields\.(\d+)\.(type|max_files)$/', $property, $m) === 1 && isset($this->fields[(int) $m[1]])) {
            $this->fields[(int) $m[1]] = $this->fileSettings($this->fields[(int) $m[1]]);
        }
    }

    public function toggleField(string $key): void
    {
        $this->openKey = $this->openKey === $key ? null : $key;
    }

    /** «Lên» / «Xuống». */
    public function move(string $key, int $step): void
    {
        $i = $this->indexOf($key);
        if ($i !== null) {
            $this->sortField($key, $i + ($step < 0 ? -1 : 1));
        }
    }

    /** A field dropped at $position (wire:sort). */
    public function sortField(string $key, int $position): void
    {
        $i = $this->indexOf($key);
        if ($i === null || $this->sharedRefused()) {
            return;
        }
        $row = array_splice($this->fields, $i, 1);
        array_splice($this->fields, max(0, min($position, count($this->fields))), 0, $row);
    }

    /** «Thêm ô»: a new empty field of $type, opened, its name field focused. */
    public function addField(string $type): void
    {
        if (! array_key_exists($type, $this->kinds()) || count($this->fields) >= FormDraft::MAX_FIELDS || $this->sharedRefused()) {
            return;
        }
        // The inbox fills a lead's email and phone (and the reply address) from the fields keyed
        // `email` and `phone`: the first such field added takes that key.
        // A key still waiting in «Hoàn tác» stays taken, so the undo can put its field back.
        $key = ['email' => 'email', 'tel' => 'phone'][$type] ?? '';
        if ($key === '' || $this->indexOf($key) !== null || ($this->removed['field']['key'] ?? null) === $key) {
            $n = (int) (microtime(true) * 1000);
            do {
                $key = $type.'_'.base_convert((string) $n++, 10, 36);
            } while ($this->indexOf($key) !== null || ! FormFieldTypes::isUsableKey($key));
        }
        $this->fields[] = $this->fileSettings(['key' => $key, 'type' => $type, 'label' => '', 'required' => false, 'choices' => '', 'accept' => '', 'max_files' => 0]);
        $this->openKey = $key;
        $this->dispatch('bm-focus', id: 'bm-l-'.$key);
    }

    /** «Xóa ô này»: gone from the draft; «Hoàn tác» puts it back where it was. */
    public function removeField(string $key): void
    {
        $i = $this->indexOf($key);
        if ($i === null || $this->sharedRefused()) {
            return;
        }
        $field = $this->fields[$i];
        array_splice($this->fields, $i, 1);
        if ($this->openKey === $key) {
            $this->openKey = null;
        }
        $this->removed = ['field' => $field, 'index' => $i];
        $label = trim($field['label']) !== '' ? $field['label'] : __('form_builder.type.'.$field['type']);
        $this->toast(__('form_builder.toast_field_removed', ['label' => $label]));
    }

    public function undoLast(): void
    {
        $op = $this->removed;
        $this->removed = null;
        $this->toastMsg = '';
        if ($op !== null && $this->indexOf($op['field']['key']) === null) {
            array_splice($this->fields, min($op['index'], count($this->fields)), 0, [$op['field']]);
        }
    }

    public function closeToast(int $seq = 0): void
    {
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toastMsg = '';
            $this->removed = null;
        }
    }

    /** «Thêm email» (or Enter in its box). */
    public function addEmail(): void
    {
        $email = trim($this->newEmail);
        if ($email === '' || $this->sharedRefused()) {
            return;
        }
        $this->emailError = match (true) {
            ! FormDraft::isEmail($email) => __('form_builder.email_invalid'),
            in_array(mb_strtolower($email), array_map(mb_strtolower(...), $this->emails), true) => __('form_builder.email_dup'),
            count($this->emails) >= FormDraft::MAX_EMAILS => __('form_builder.email_max', ['max' => FormDraft::MAX_EMAILS]),
            default => '',
        };
        if ($this->emailError === '') {
            $this->emails[] = $email;
            $this->newEmail = '';
        }
    }

    public function removeEmail(int $index): void
    {
        if (isset($this->emails[$index]) && ! $this->sharedRefused()) {
            array_splice($this->emails, $index, 1);
        }
    }

    /** «Xem lỗi đầu tiên» (the save bar's event): open what holds it and put the cursor there. */
    public function openFirstError(): void
    {
        $first = array_key_first($this->errorsByKey());
        if ($first === null) {
            return;
        }
        $this->touched[$first] = true;
        if (str_starts_with($first, 'f:')) {
            $this->openKey = substr($first, 2);
            $this->dispatch('bm-focus', id: $this->errorsByKey()[$first]['id']);

            return;
        }
        $this->dispatch('bm-focus', id: $this->errorsByKey()[$first]['id']);
    }

    public function save(): void
    {
        $errors = $this->errorsByKey();
        if ($errors !== []) {
            $this->touched = [...$this->touched, ...array_fill_keys(array_keys($errors), true)];
            $this->openFirstError();

            return;
        }
        if ($this->changeCount() === 0 || ! $this->entitled()) {
            return;
        }

        $form = $this->ulid !== null ? Form::query()->where('ulid', $this->ulid)->first() : null;
        if (($form === null && ($this->ulid !== null || Gate::denies('create', Form::class))) || ($form !== null && Gate::denies('update', $form))) {
            $this->toast(__('form_builder.toast_cannot'), 'error');

            return;
        }

        if ($form === null && Form::query()->count() >= FormDraft::MAX_FORMS) {
            $this->toast(__('form_builder.forms_max', ['max' => FormDraft::MAX_FORMS]), 'error');

            return;
        }
        $draft = $this->draft();
        $dropped = 0;
        try {
            if ($form !== null && $this->translating()) {
                ['form' => $form, 'dropped' => $dropped] = app(SaveForm::class)->translate($form, $draft, $this->locale, $this->source, $this->choiceValues, $this->choiceSources);
            } else {
                $form = app(SaveForm::class)->handle($form, $draft, $this->locale);
            }
        } catch (InvalidArgumentException) {
            $this->toast(__('form_builder.toast_failed'), 'error');

            return;
        }

        if ($this->ulid === null) {
            $this->ulid = $form->ulid;
            $this->isNew = false;
            $this->js('history.replaceState(history.state, "", '.Js::from(route('admin.form-builder.edit', $form->ulid)).')');
        }
        $this->touched = [];
        $this->removed = null;
        $form->load('fields');
        if ($this->translating()) {
            // this language as now stored: the main language's changes made since it opened come in,
            // so each line stays beside the choice it belongs to for the next save
            $draft = FormDraft::translation($form, $this->locale, $this->source);
            $this->fill($this->draftProps($draft));
            $this->saved = $draft;
            $draft = $this->draft();
        }
        $this->saved = $draft;
        $this->readSourceTexts($form);
        $this->readLangBar($form);
        $this->savedAt = app(SiteTime::class)->forDisplay(now())?->format('H:i');
        // a line typed for a choice the main language has removed meanwhile is not kept: say so
        if ($dropped > 0) {
            $this->toast(trans_choice('form_builder.toast_saved_dropped', $dropped, ['n' => $dropped, 'lang' => LangBar::name($this->source)]), 'info');

            return;
        }
        $this->toast(__('form_builder.toast_saved'));
    }

    /** «Bỏ thay đổi»: back to the form as last saved (a new form: its starting point). */
    public function resetChanges(): void
    {
        $this->fill($this->draftProps($this->saved));
        $this->touched = [];
        $this->removed = null;
        $this->emailError = '';
        $this->newEmail = '';
        $this->openKey = null;
    }

    /** The board's count of changes; a form not yet saved counts one more (it does not exist yet). */
    public function changeCount(): int
    {
        $a = $this->draft();
        $b = $this->saved;
        $n = $this->isNew ? 1 : 0;
        foreach (['name', 'success', 'emails', 'active'] as $k) {
            if ($a[$k] !== $b[$k]) {
                $n++;
            }
        }
        $before = [];
        foreach ($b['fields'] as $f) {
            $before[$f['key']] = $f;
        }
        foreach ($a['fields'] as $f) {
            if (! isset($before[$f['key']]) || $before[$f['key']] !== $f) {
                $n++;
            }
            unset($before[$f['key']]);
        }
        $n += count($before);
        $keysA = array_column($a['fields'], 'key');
        $keysB = array_column($b['fields'], 'key');
        if (array_values(array_intersect($keysA, $keysB)) !== array_values(array_intersect($keysB, $keysA))) {
            $n++;
        }

        return $n;
    }

    public function render(): View
    {
        // The plan first, then the permission: either card, and no form is read.
        if (($locked = $this->lockedView()) !== null) {
            return $this->titled($locked);
        }

        $errors = $this->errorsByKey();
        $shown = array_intersect_key($errors, $this->touched + ['fields' => true]); // a form left with nothing to fill in says so at once
        $changes = $this->changeCount();
        $firstError = array_key_first($errors);
        $title = $this->isNew && $this->ulid === null ? __('form_builder.title_new') : __('form_builder.title_edit', ['name' => $this->translating() ? $this->sourceTexts['name'] : trim($this->saved['name'])]);
        $user = auth()->user();

        return $this->titled(view('admin::livewire.forms.editor', [
            'title' => $title,
            'changes' => $changes,
            'invalid' => count($errors),
            'shown' => $shown,
            'firstError' => $firstError,
            'kinds' => $this->kinds(),
            'fileKinds' => FormFieldTypes::fileKinds(),
            'fileMaxMb' => LeadFileStorage::maxBytes() / (1024 * 1024),
            'sendLabel' => __('forms.submit_button', [], $this->locale),
            'privacyLabel' => __('forms.privacy_link', [], $this->locale),
            'thanksDefault' => __('form_builder.thanks_default', [], $this->locale),
            'leave' => trans_choice('form_builder.leave_body', $changes, ['n' => $changes]),
            // «Mở Trình dựng trang» opens the pages list, for a member who may open it
            'pagesHref' => Gate::allows('entries.view') ? route('admin.entries.index', ['section' => 'pages']) : null,
            'translating' => $this->translating(),
            'textDir' => StorefrontChrome::dir($this->locale),
            'langName' => LangBar::name($this->locale),
            'sharedWhy' => $this->translating() ? $this->sharedWhy() : '',
            'langManage' => $this->langBar !== [] && $user instanceof User && $user->can('settings.manage') ? route('admin.settings.languages') : null,
        ]), $title);
    }

    /**
     * Each thing in error with the message under it and the field to focus, in the page's order.
     *
     * @return array<string, array{msg: string, id: string}>
     */
    private function errorsByKey(): array
    {
        if ($this->translating()) {
            return $this->translationErrors();
        }
        $out = [];
        $name = trim($this->name);
        if ($name === '') {
            $out['name'] = ['msg' => __('form_builder.name_error'), 'id' => 'bm-name'];
        } elseif (mb_strlen($name) > FormDraft::MAX_NAME) {
            $out['name'] = ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_NAME]), 'id' => 'bm-name'];
        }
        if (! FormDraft::hasAnswerField(array_column($this->fields, 'type'))) {
            // the add button, or the message itself once the form holds the most fields
            $out['fields'] = ['msg' => __('form_builder.fields_none'), 'id' => count($this->fields) < FormDraft::MAX_FIELDS ? 'bm-add' : 'bm-add-err'];
        }
        foreach ($this->fields as $f) {
            $label = trim($f['label']);
            $lines = FormDraft::lines($f['choices'], unique: false); // every line can become a stored choice
            $msg = match (true) {
                $label === '' => ['msg' => __('form_builder.field_label_error'), 'id' => 'bm-l-'.$f['key']],
                mb_strlen($label) > FormDraft::MAX_NAME => ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_NAME]), 'id' => 'bm-l-'.$f['key']],
                FormFieldTypes::isChoice($f['type']) && $lines === [] => ['msg' => __('form_builder.choices_error'), 'id' => 'bm-o-'.$f['key']],
                FormFieldTypes::isChoice($f['type']) && (count($lines) > FormDraft::MAX_CHOICES || array_filter($lines, fn (string $l): bool => mb_strlen($l) > FormDraft::MAX_NAME) !== []) => ['msg' => __('form_builder.choices_long', ['n' => FormDraft::MAX_CHOICES, 'max' => FormDraft::MAX_NAME]), 'id' => 'bm-o-'.$f['key']],
                default => null,
            };
            if ($msg !== null) {
                $out['f:'.$f['key']] = $msg;
            }
        }
        if (mb_strlen(trim($this->success)) > FormDraft::MAX_SUCCESS) {
            $out['success'] = ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_SUCCESS]), 'id' => 'bm-thx'];
        }

        return $out;
    }

    /** @return Draft */
    private function draft(): array
    {
        return [
            'name' => trim($this->name),
            'success' => trim($this->success),
            'active' => $this->active,
            'emails' => $this->emails,
            'fields' => array_map(fn (array $f): array => $this->fileSettings([
                'key' => Cast::string($f['key']),
                'type' => Cast::string($f['type']),
                'label' => trim(Cast::string($f['label'])),
                'required' => Cast::bool($f['required']),
                'choices' => ! FormFieldTypes::isChoice(Cast::string($f['type'])) ? '' : implode("\n", $this->translating()
                    ? FormDraft::translatedLines(Cast::string($f['choices']))
                    : FormDraft::lines(Cast::string($f['choices']), unique: false)), // repeats kept: two choices can show the same words
                'accept' => Cast::string($f['accept']),
                'max_files' => Cast::int($f['max_files']),
            ]), $this->fields),
        ];
    }

    /**
     * @param  Draft  $draft
     * @return array<string, mixed>
     */
    private function draftProps(array $draft): array
    {
        return ['name' => $draft['name'], 'success' => $draft['success'], 'active' => $draft['active'], 'emails' => $draft['emails'], 'fields' => $draft['fields']];
    }

    /**
     * The kinds of field offered here: every one, the file type only while the platform takes some
     * kind of file.
     *
     * @return array<string, string>
     */
    private function kinds(): array
    {
        return FormFieldTypes::fileKinds() === [] ? array_diff_key(self::KINDS, [FormFieldTypes::FILE => true]) : self::KINDS;
    }

    /** The kind the field of $key had when last saved, if it was a file field. */
    private function savedKind(string $key): ?string
    {
        foreach ($this->saved['fields'] as $f) {
            if ($f['key'] === $key && $f['type'] === FormFieldTypes::FILE) {
                return $f['accept'];
            }
        }

        return null;
    }

    /**
     * $field with its file settings made whole: a file field gets a kind it may have (the first the
     * platform takes, unless it has one) and 1 to 5 files (3 unless set); any other field '' and 0.
     *
     * @param  DraftField  $field
     * @return DraftField
     */
    private function fileSettings(array $field): array
    {
        if ($field['type'] !== FormFieldTypes::FILE) {
            return [...$field, 'accept' => '', 'max_files' => 0];
        }
        $kinds = FormFieldTypes::fileKinds();
        $accept = in_array($field['accept'], $kinds, true) || $field['accept'] === $this->savedKind($field['key'])
            ? $field['accept']
            : ($kinds[0] ?? FormFieldTypes::DEFAULT_KIND);
        $max = (int) $field['max_files'];

        return [...$field, 'accept' => $accept, 'max_files' => $max >= 1 && $max <= FormFieldTypes::MAX_FILES ? $max : FormFieldTypes::DEFAULT_MAX_FILES];
    }

    private function indexOf(string $key): ?int
    {
        foreach ($this->fields as $i => $f) {
            if ($f['key'] === $key) {
                return $i;
            }
        }

        return null;
    }

    /** Another language than the site's main one is open: only its texts are edited. */
    private function translating(): bool
    {
        return $this->locale !== $this->source;
    }

    /** Why a shared setting is locked here: where to change it (the editors' words). */
    private function sharedWhy(): string
    {
        return Cast::string(__('entries.ed.shared_guard', ['lang' => LangBar::name($this->source)]));
    }

    /**
     * In another language than the main one, what every language shares is not changed (its controls
     * are locked there, so only a hand-made request gets here): say where it is changed. Returns
     * whether it refused.
     */
    private function sharedRefused(): bool
    {
        if (! $this->translating()) {
            return false;
        }
        $this->toast($this->sharedWhy(), 'error');

        return true;
    }

    /**
     * The errors of another language's texts: none is required (an empty one shows the main
     * language's), each keeps its length, and a choices box never has more lines than its field
     * has choices.
     *
     * @return array<string, array{msg: string, id: string}>
     */
    private function translationErrors(): array
    {
        $out = [];
        if (mb_strlen(trim($this->name)) > FormDraft::MAX_NAME) {
            $out['name'] = ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_NAME]), 'id' => 'bm-name'];
        }
        foreach ($this->fields as $f) {
            $lines = FormDraft::translatedLines($f['choices']);
            $choices = count($this->choiceValues[$f['key']] ?? []);
            $msg = match (true) {
                mb_strlen(trim($f['label'])) > FormDraft::MAX_NAME => ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_NAME]), 'id' => 'bm-l-'.$f['key']],
                FormFieldTypes::isChoice($f['type']) && count($lines) > $choices => ['msg' => trans_choice('form_builder.choices_count_error', $choices, ['n' => $choices, 'lang' => LangBar::name($this->source)]), 'id' => 'bm-o-'.$f['key']],
                FormFieldTypes::isChoice($f['type']) && array_filter($lines, fn (string $l): bool => mb_strlen($l) > FormDraft::MAX_NAME) !== [] => ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_NAME]), 'id' => 'bm-o-'.$f['key']],
                default => null,
            };
            if ($msg !== null) {
                $out['f:'.$f['key']] = $msg;
            }
        }
        if (mb_strlen(trim($this->success)) > FormDraft::MAX_SUCCESS) {
            $out['success'] = ['msg' => __('form_builder.too_long', ['max' => FormDraft::MAX_SUCCESS]), 'id' => 'bm-thx'];
        }

        return $out;
    }

    /** The main language's texts of $form (its fields loaded), as they are stored, and its choice values with their main-language labels. */
    private function readSourceTexts(Form $form): void
    {
        $source = FormDraft::fromForm($form, $this->source);
        $this->sourceTexts = [
            'name' => $source['name'],
            'success' => $source['success'],
            'labels' => array_column($source['fields'], 'label', 'key'),
            'choices' => array_column($source['fields'], 'choices', 'key'),
        ];
        $this->choiceValues = [];
        $this->choiceSources = [];
        foreach ($form->fields as $field) {
            if ($field->key !== Form::CONSENT_KEY && FormFieldTypes::isChoice($field->type)) {
                $choices = FormFieldTypes::choices($field);
                $this->choiceValues[$field->key] = array_column($choices, 'value');
                $this->choiceSources[$field->key] = array_map(fn (array $c): string => FormDraft::own($c['label'], $this->source), $choices);
            }
        }
    }

    /**
     * The language bar of a saved form on a site with more than one language: each language's state
     * from what $form (its fields loaded) has written in it (FormLangStates), each opening this editor
     * in that language (the main one without `?locale=`).
     */
    private function readLangBar(Form $form): void
    {
        $languages = app(SiteLocale::class);
        $enabled = $languages->enabled();
        if (count($enabled) < 2) {
            $this->langBar = [];

            return;
        }
        $states = FormLangStates::read($form, $this->source, $enabled);
        $rows = [];
        foreach ($enabled as $code) {
            $rows[$code] = ['done' => $code === $this->source ? $states['total'] : $states['done'][$code], 'mark' => null, 'live' => false];
        }
        $ulid = $form->ulid;
        $source = $this->source;
        $this->langBar = LangBar::items($rows, $enabled, $source, $languages->public(), $this->locale, $states['total'],
            static fn (string $code): string => route('admin.form-builder.edit', ['ulid' => $ulid] + ($code !== $source ? ['locale' => $code] : [])));
    }

    private function toast(string $message, string $tone = 'success'): void
    {
        if ($tone === 'error') {
            $this->removed = null;
        }
        $this->toastMsg = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    private function titled(View $view, ?string $title = null): View
    {
        $view->title($title ?? __('form_builder.title'));

        return $view;
    }
}
