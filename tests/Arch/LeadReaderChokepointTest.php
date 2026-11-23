<?php

use Illuminate\Support\Facades\File;

/*
 * A lead in the «Khách liên hệ» Trash carries a deleted_at, and the model has no global scope for it:
 * the screens decide which side of the Trash they show. LeadListQuery::leads() is the one place that
 * does, and it also hides the leads of a form being deleted. A reader in the leads screens that asks
 * FormSubmission (or the table) by itself would list trashed leads among the kept ones, so every read
 * there goes through leads(). Writers that act on ids already read through leads(), and the one count
 * that names deleted_at itself, are listed below with their reason. The purges, the erase action and
 * the form delete live in the Forms module and are not read by this rule.
 */

/** Where the leads screens live: the Livewire components, the support classes, the dashboard's reader and the controllers. */
function leadScreenFiles(): array
{
    $files = [];
    foreach (File::allFiles(app_path('Modules/Admin/Livewire/Forms')) as $file) {
        $files[] = $file->getPathname();
    }
    foreach (File::allFiles(app_path('Modules/Admin/Support/Leads')) as $file) {
        $files[] = $file->getPathname();
    }
    foreach (File::allFiles(app_path('Modules/Admin/Support/Dashboard')) as $file) {
        $files[] = $file->getPathname();
    }
    foreach (File::glob(app_path('Modules/Admin/Http/Controllers/Lead*.php')) as $path) {
        $files[] = $path;
    }

    return $files;
}

/**
 * What the allow-list lets through, per file name: the exact text and why. Everything else that names
 * the model or the table is a reader that skips leads().
 *
 * @return array<string, array<string, string>> file name => [regex => reason]
 */
function leadReaderAllowed(): array
{
    return [
        'LeadListQuery.php' => [
            '/FormSubmission::query\(\)/' => 'leads() itself: the one query that picks a side of the Trash',
            '/\bform_submissions\.\w+/' => 'column names of the query leads() started, not a new read',
        ],
        'Inbox.php' => [
            '/FormSubmission::query\(\)->where(?:Key|In)\([^;]*?->update\(/s' => 'writes by id of leads already read through leads(): trash, restore, change of status',
        ],
        'LeadFileDownloadController.php' => [
            '/FormSubmission::query\(\)->whereKey\(\$file->form_submission_id\)->first\(\[[^\]]*\'deleted_at\'\]\)/' => 'reads the lead of one stored file with its deleted_at, and LeadPolicy::downloadFile decides for a trashed one',
        ],
        'FormList.php' => [
            '/from form_submissions s where s\.site_id = \? and s\.form_id = f\.id and s\.status <> \\\\\'spam\\\\\'\'\.\(\$withTrash \? \'\' : \' and s\.deleted_at is null\'\)/' => 'the count per form: it names deleted_at itself, with or without the Trash',
        ],
    ];
}

/**
 * @param  array<string, string>  $allowed  regex => reason
 * @return list<string>
 */
function leadReaderBypasses(string $source, array $allowed = []): array
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    foreach (array_keys($allowed) as $pattern) {
        $code = (string) preg_replace($pattern, '', $code);
    }

    preg_match_all('/FormSubmission::(?!class\b)\w+|\bnew\s+FormSubmission\b|FormSubmission::class\)\s*->|\bform_submissions\b|->submissions\s*\(|[\'"]submissions[\'"]/', $code, $found);

    return $found[0];
}

it('reads leads in the leads screens only through LeadListQuery::leads()', function (): void {
    $files = leadScreenFiles();
    $violations = [];
    foreach ($files as $path) {
        $allowed = leadReaderAllowed()[basename($path)] ?? [];
        foreach (leadReaderBypasses(File::get($path), $allowed) as $hit) {
            $violations[] = str_replace(base_path().'/', '', $path).": {$hit}";
        }
    }

    expect(count($files))->toBeGreaterThan(5)
        ->and($violations)->toBe([]);
});

it('keeps every allow-list entry in use', function (): void {
    foreach (leadReaderAllowed() as $name => $patterns) {
        $path = collect(leadScreenFiles())->first(fn (string $p): bool => basename($p) === $name);
        expect($path)->not->toBeNull();
        foreach (array_keys($patterns) as $pattern) {
            expect(preg_match($pattern, File::get($path)))->toBe(1, "{$name} no longer has {$pattern}");
        }
    }
});

it('catches a reader that skips leads()', function (string $source): void {
    expect(leadReaderBypasses("<?php\n".$source))->not->toBe([]);
})->with([
    'model query' => "\$rows = FormSubmission::query()->where('status', 'new')->get();",
    'model static' => 'FormSubmission::where(\'id\', 1)->first();',
    'model find' => 'FormSubmission::find($id);',
    'query builder on the table' => "DB::table('form_submissions')->where('site_id', 1)->count();",
    'raw SQL' => "DB::select('select id from form_submissions where site_id = ?', [1]);",
    'new instance' => '(new FormSubmission)->newQuery()->get();',
    'container instance' => 'app(FormSubmission::class)->newQuery()->get();',
    'relation of a form' => '$form->submissions()->count();',
]);

it('lets the model class, comments and the type hints through', function (string $source): void {
    expect(leadReaderBypasses("<?php\n".$source))->toBe([]);
})->with([
    'class name' => "'auditable_type' => FormSubmission::class,",
    'comment' => '// FormSubmission::query() would skip the Trash and read form_submissions',
    'type hint' => 'private function who(FormSubmission $lead): string {}',
    'leads()' => 'LeadListQuery::leads()->where(\'ulid\', $ulid)->first();',
]);
