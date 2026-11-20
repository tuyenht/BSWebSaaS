<?php

use Illuminate\Support\Facades\File;
use Tests\Fixtures\ModelWriteBypasses;

/*
 * A lead's search words (lead_search_terms) are written by the model events of FormSubmission, in the
 * transaction of the save that stored or changed its name, email or phone. A write that skips the
 * events leaves the words behind, and the lead is then missing from the inbox search (or found by an
 * old name). So the writes of form_submissions.name, email and phone that skip the model are banned
 * here: query-builder writes that name the columns or hide their values, raw SQL that writes them,
 * bulk updates/inserts/upserts on the model or the submissions relation, and quiet saves (…Quietly,
 * withoutEvents) in a file that names the model. A delete needs no rule: the words go with the lead
 * (ON DELETE CASCADE). It reads app/, the seeders, routes/ and the migrations that come after the word
 * table's own. `forms:search-terms` corrects what still slips through.
 */

/**
 * @return list<string>
 */
function leadWordBypasses(string $source): array
{
    return (new ModelWriteBypasses(
        tables: '(?:form_submissions)',
        columns: 'name|email|phone',
        models: 'FormSubmission',
        relations: 'submissions',
        collection: 'submissions',
        columnAware: true,
        quietSaves: true,
    ))->find($source);
}

it('writes a lead\'s name, email or phone only through the model, which keeps the search words', function (): void {
    $files = [];
    foreach ([app_path(), database_path('seeders'), base_path('routes')] as $root) {
        foreach (File::allFiles($root) as $file) {
            $files[] = $file->getPathname();
        }
    }
    // the migrations after the one that creates the word table: an older one ran before any word
    // existed, and the fill after that deploy writes the words of what it left
    $creation = '2086_01_01_000000_create_lead_search_terms_table.php';
    expect(File::exists(database_path('migrations/'.$creation)))->toBeTrue();
    foreach (File::files(database_path('migrations')) as $file) {
        if (strcmp($file->getFilename(), $creation) > 0) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];
    $scanned = 0;
    foreach ($files as $path) {
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        $scanned++;
        foreach (leadWordBypasses(File::get($path)) as $hit) {
            $violations[] = str_replace(base_path().'/', '', $path).": {$hit}";
        }
    }

    expect($scanned)->toBeGreaterThan(500)
        ->and($violations)->toBe([]);
});

it('catches each banned way of writing a lead\'s name, email or phone', function (string $source): void {
    expect(leadWordBypasses("<?php\n".$source))->not->toBe([]);
})->with([
    'DB::table update of the name' => "DB::table('form_submissions')->where('id', \$id)->update(['name' => \$name]);",
    'DB::table update of the phone' => "DB::table('form_submissions')->whereIn('id', \$ids)->update(['phone' => null]);",
    'DB::table insert of rows kept in a variable' => "DB::table('form_submissions')->insert(\$rows);",
    'DB::table builder kept in a variable' => "\$q = DB::table('form_submissions')->where('id', \$id);\n\$q->update(['email' => \$email]);",
    'raw SQL update' => "DB::update('UPDATE form_submissions SET email = ? WHERE id = ?', [\$email, \$id]);",
    'raw SQL insert' => "DB::statement(\"insert into form_submissions (ulid, site_id, form_id, name) values ('a', 1, 2, 'X')\");",
    'model bulk update' => "FormSubmission::query()->whereKey(\$ids)->update(['name' => \$name]);",
    'relation bulk update' => "\$form->submissions()->update(['phone' => \$phone]);",
    'model insert' => 'FormSubmission::insert($rows);',
    'model upsert' => "FormSubmission::query()->upsert(\$rows, ['ulid'], ['name']);",
    'column in a variable' => 'FormSubmission::query()->update([$column => $value]);',
    'quiet save' => "\$lead = FormSubmission::query()->first();\n\$lead->saveQuietly();",
    'without events' => "FormSubmission::withoutEvents(fn () => \$lead->update(['name' => 'X']));",
]);

it('lets the model roads and the other columns through', function (string $source): void {
    expect(leadWordBypasses("<?php\n".$source))->toBe([]);
})->with([
    'relation create' => "\$form->submissions()->create(['name' => \$name, 'email' => \$email, 'phone' => \$phone]);",
    'instance update' => "\$lead->update(['name' => \$name]);",
    'a status change in bulk' => "FormSubmission::query()->whereKey(\$ids)->update(['status' => 'read']);",
    'a status change through the relation' => "\$form->submissions()->where('status', 'new')->update(['status' => 'archived']);",
    'a read through the table' => "DB::table('form_submissions')->where('site_id', \$id)->get(['id', 'name', 'email', 'phone']);",
    'a lead deleted' => 'FormSubmission::query()->whereKey($id)->delete();',
    'the word table itself' => "DB::statement('insert into lead_search_terms (site_id, term, lead_id) values (?, ?, ?)', [1, 'a', 2]);",
]);
