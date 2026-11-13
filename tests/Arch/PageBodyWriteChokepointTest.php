<?php

use Illuminate\Support\Facades\File;
use Tests\Fixtures\ModelWriteBypasses;

/*
 * A page or post body is sanitized on write by the casts on entry_translations.body and
 * entry_drafts.content (ADR-19), and a reusable block's body by the same cast on
 * content_block_translations.body. The cast runs only when the write goes through the model, so the
 * writes that skip it are banned here: query-builder inserts/updates on the two tables (DB::table,
 * raw SQL, a model's or a relation's bulk update/insert/upsert, toBase()/getQuery()/newQuery()/
 * toQuery(), the table name read from the model), raw attributes, and JSON-path keys. A builder kept
 * in a variable counts as the builder for the rest of its function, a bulk update whose column is
 * not a literal counts as a body write, and raw SQL whose table is computed counts as a write to
 * the two tables in a file that names them. An update called on one model (after first…/find…/
 * create…) is the instance update, which does go through the cast. PageBodySanitizingTest proves
 * each road that does go through the model stores clean HTML.
 *
 * A coarse tripwire, read statement by statement from PHP tokens (a closure inside a call stays in
 * its statement; its own body is read as statements of its own): it sees the literal spellings
 * only. The second test proves it catches each banned form, so it cannot pass by seeing nothing.
 */

/**
 * The banned body writes in a PHP source, as "rule @ line".
 *
 * @return list<string>
 */
function pageBodyBypasses(string $source): array
{
    return (new ModelWriteBypasses(
        tables: '(?:entry_translations|entry_drafts|content_block_translations)',
        columns: 'body|content',
        models: 'EntryTranslation|EntryDraft|ContentBlockTranslation',
        relations: 'translations|draft',
        collection: 'translations',
    ))->find($source);
}

it('writes a page or post body only through the model and its sanitizing cast', function (): void {
    $violations = [];
    $scanned = 0;
    foreach ([app_path(), database_path('seeders'), database_path('migrations')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }
            $path = str_replace(base_path().'/', '', $file->getPathname());
            $scanned++;
            foreach (pageBodyBypasses(File::get($file->getPathname())) as $hit) {
                $violations[] = "{$path}: {$hit}";
            }
        }
    }

    expect($scanned)->toBeGreaterThan(500)
        ->and($violations)->toBe([]);
});

it('catches each banned way of writing a body', function (string $source): void {
    expect(pageBodyBypasses("<?php\n".$source))->not->toBe([]);
})->with([
    'DB::table update' => "DB::table('entry_translations')->where('id', \$id)->update(['body' => \$json]);",
    'DB::table with the schema' => "DB::table('public.entry_translations')->where('id', \$id)->update(['body' => \$json]);",
    'DB::table insert' => "DB::table('entry_drafts')->insert(['entry_id' => 1, 'content' => \$json]);",
    'DB::table upsert' => "DB::connection('pgsql_admin')->table('entry_translations')->upsert(\$rows, ['id']);",
    'query from' => "DB::query()->from('entry_drafts')->updateOrInsert(['entry_id' => 1], ['content' => \$json]);",
    'raw SQL update' => "DB::update('UPDATE entry_translations SET body = ? WHERE id = ?', [\$json, \$id]);",
    'raw SQL insert' => "DB::statement(\"insert into entry_drafts (content) values ('{\$json}')\");",
    'heredoc SQL' => "DB::unprepared(<<<'SQL'\n    update public.entry_translations set body = '{}'\n    SQL);",
    'model bulk update' => "EntryTranslation::query()->where('entry_id', \$id)->update(['body' => \$body]);",
    'model bulk update by static where' => "EntryDraft::where('entry_id', \$id)->update(['content' => \$content]);",
    'relation bulk update' => "\$entry->translations()->update(['title' => 'A', 'body' => \$body]);",
    'relation of the change' => "\$entry->draft()->update(['content' => \$content]);",
    'model insert' => 'EntryTranslation::insert($rows);',
    'model upsert' => "EntryTranslation::query()->upsert(\$rows, ['entry_id', 'locale'], ['body']);",
    'toBase' => "\$query->toBase()->update(['body' => \$json]);",
    'raw attributes' => "\$translation->setRawAttributes(['body' => \$json] + \$translation->getAttributes());",
    'JSON-path key' => "\$translation->update(['body->blocks' => \$blocks]);",
    'forceFill with raw JSON' => "\$translation->forceFill(['body' => json_encode(\$body)]);",
    'newQuery bulk update' => "\$translation->newQuery()->where('id', \$id)->update(['body' => \$json]);",
    'builder kept in a variable' => "\$q = EntryTranslation::query()->where('x', 1);\n\$q->update(['body' => \$json]);",
    'closure inside the chain' => "EntryTranslation::query()->where(function (\$q) { \$q->where('a', 1); })->update(['body' => \$json]);",
    'collection toQuery' => "\$entry->translations->toQuery()->update(['body' => \$json]);",
    'table name from the model' => "DB::table((new EntryTranslation)->getTable())->update(['body' => \$json]);",
    'computed SQL' => "DB::update(sprintf('UPDATE %s SET body = ?', 'entry_translations'), [\$json]);",
    'column in a variable' => 'EntryTranslation::query()->update([$column => $json]);',
    'relation kept in a variable' => "\$rel = \$entry->translations();\n\$rel->update(['body' => \$json]);",
    'variable used in a later closure' => "\$rel = \$entry->translations();\nDB::transaction(function () use (\$rel, \$json) { \$rel->update(['body' => \$json]); });",
    'interpolated SQL' => "DB::statement(\"UPDATE {\$table} SET body = '{}'\"); // entry_translations",
    'computed table and column' => "DB::update(sprintf('UPDATE %s SET %s = ?', \$table, \$column), [\$json]); // EntryTranslation",
    'SQL all computed' => 'DB::statement($sql); // entry_drafts',
    'block body bulk update' => "ContentBlockTranslation::query()->where('content_block_id', \$id)->update(['body' => \$body]);",
    'block body raw insert' => "DB::table('content_block_translations')->insert(['locale' => 'vi', 'body' => \$json]);",
]);

it('lets the model roads through', function (string $source): void {
    expect(pageBodyBypasses("<?php\n".$source))->toBe([]);
})->with([
    'relation create' => "\$entry->translations()->create(['locale' => 'vi', 'body' => \$body]);",
    'updateOrCreate' => "\$entry->translations()->updateOrCreate(['locale' => \$locale], ['body' => \$body]);",
    'fill and save' => "\$entry->translations()->firstOrNew(['locale' => 'vi'])->fill(['body' => \$body])->save();",
    'instance update' => "\$translation->update(['body' => \$body]);",
    'instance update when found' => "EntryTranslation::query()->where('id', \$id)->first()?->update(['body' => \$body]);",
    'a read through the table' => "DB::table('entry_translations')->where('entry_id', \$id)->value('body');",
    'another table' => "DB::table('product_translations')->where('id', \$id)->update(['description' => \$html]);",
    'workflow state' => "EntryDraft::query()->whereKey(\$id)->update(['workflow_state' => null]);",
    'instance update after find' => "EntryTranslation::query()->find(\$id)->update(['body' => \$body]);",
    'instance kept after firstOrNew' => "\$translation = \$entry->translations()->firstOrNew(['locale' => 'vi']);\n\$translation->update(['body' => \$body]);",
    'instance kept after find' => "\$translation = EntryTranslation::query()->whereKey(\$id)->firstOrFail();\n\$translation->update([\$column => \$body]);",
    'a closure body is its own statement' => "DB::transaction(function () use (\$entry) { \$entry->translations()->firstOrNew(['locale' => 'vi'])->fill(['body' => []])->save(); \$entry->update(['status' => 'draft']); });",
    'a variable of another function' => "function a() { \$q = EntryTranslation::query(); return \$q; }\nfunction b(\$q) { \$q->update(['body' => 1]); }",
    'literal SQL on another table' => "DB::statement(\"UPDATE entries SET status = 'draft'\"); // entry_translations",
    'another model\'s computed table' => 'DB::connection($c)->update("update {$user->getTable()} set preferences = ? where id = ?", [$v, $id]); // EntryTranslation',
    'index on a computed table' => 'DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$table} {$definition}"); // entry_translations',
]);
