<?php

use Illuminate\Support\Facades\File;

/*
 * A page or post body is sanitized on write by the casts on entry_translations.body and
 * entry_drafts.content (ADR-19). The cast runs only when the write goes through the model, so the
 * writes that skip it are banned here: query-builder inserts/updates on the two tables (DB::table,
 * raw SQL, a model's or a relation's bulk update/insert/upsert, toBase()/getQuery()), raw
 * attributes, and JSON-path keys. PageBodySanitizingTest proves each road that does go through the
 * model stores clean HTML.
 *
 * A coarse tripwire, read statement by statement from PHP tokens: it sees the literal spellings
 * only. The second test proves it catches each banned form, so it cannot pass by seeing nothing.
 */

/**
 * The banned body writes in a PHP source, as "rule @ line".
 *
 * @return list<string>
 */
function pageBodyBypasses(string $source): array
{
    $tables = '(?:entry_translations|entry_drafts)';
    $columns = "['\"](?:body|content)(?:->[^'\"]*)?['\"]";
    $anchor = '~(?:\b(?:EntryTranslation|EntryDraft)::(?!class\b)|->(?:translations|draft)\(\)|->(?:toBase|getQuery)\(\))~';
    $bulkInsert = '~(?:->|::)(?:insert|insertOrIgnore|insertGetId|insertUsing|upsert|updateOrInsert|fillAndInsert)\(~';
    $bulkWrite = '~->(?:update|insert\w*|upsert|updateOrInsert|updateFrom|increment|decrement)\(~';

    $violations = [];
    $check = function (string $code, string $strings, int $line) use (&$violations, $tables, $columns, $anchor, $bulkInsert, $bulkWrite): void {
        if ($code === '') {
            return;
        }
        $rules = [
            'raw SQL write' => preg_match('~\b(?:update|insert\s+into|merge\s+into|copy)\s+(?:only\s+)?(?:public\.)?"?'.$tables.'\b~i', $strings) === 1,
            'query-builder write on the table' => preg_match('~(?:table|from|into)\([\'"]'.$tables.'\b~', $code) === 1 && preg_match($bulkWrite, $code) === 1,
            'bulk insert/upsert on the model' => preg_match($anchor, $code) === 1 && preg_match($bulkInsert, $code) === 1,
            'bulk update of the body on the model' => preg_match($anchor, $code) === 1 && preg_match('~->update\(~', $code) === 1 && preg_match('~'.$columns.'~', $code) === 1,
            'raw attributes' => str_contains($code, 'setRawAttributes(') && preg_match('~'.$columns.'~', $code) === 1,
            'JSON-path key' => preg_match("~['\"](?:body|content)->~", $code) === 1,
            'forceFill with raw JSON' => str_contains($code, 'forceFill(') && str_contains($code, 'json_encode(') && preg_match('~'.$columns.'~', $code) === 1,
        ];
        foreach ($rules as $rule => $hit) {
            if ($hit) {
                $violations[] = "{$rule} @ {$line}";
            }
        }
    };

    $code = '';
    $strings = '';
    $line = 1;
    foreach (token_get_all($source) as $token) {
        [$id, $text, $at] = is_array($token) ? $token : [null, $token, null];
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML], true)) {
            continue;
        }
        if ($id === null && in_array($text, [';', '{', '}'], true)) {
            $check($code, $strings, $line);
            $code = $strings = '';

            continue;
        }
        if ($code === '' && $at !== null) {
            $line = $at;
        }
        $code .= $text;
        if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $strings .= ' '.$text;
        }
    }
    $check($code, $strings, $line);

    return $violations;
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
]);

it('lets the model roads through', function (string $source): void {
    expect(pageBodyBypasses("<?php\n".$source))->toBe([]);
})->with([
    'relation create' => "\$entry->translations()->create(['locale' => 'vi', 'body' => \$body]);",
    'updateOrCreate' => "\$entry->translations()->updateOrCreate(['locale' => \$locale], ['body' => \$body]);",
    'fill and save' => "\$entry->translations()->firstOrNew(['locale' => 'vi'])->fill(['body' => \$body])->save();",
    'instance update' => "\$translation->update(['body' => \$body]);",
    'a read through the table' => "DB::table('entry_translations')->where('entry_id', \$id)->value('body');",
    'another table' => "DB::table('product_translations')->where('id', \$id)->update(['description' => \$html]);",
    'workflow state' => "EntryDraft::query()->whereKey(\$id)->update(['workflow_state' => null]);",
]);
