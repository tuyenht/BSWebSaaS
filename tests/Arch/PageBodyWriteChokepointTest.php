<?php

use Illuminate\Support\Facades\File;

/*
 * A page or post body is sanitized on write by the casts on entry_translations.body and
 * entry_drafts.content (ADR-19). The cast runs only when the write goes through the model, so the
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
    $tables = '(?:entry_translations|entry_drafts)';
    $columns = "['\"](?:body|content)(?:->[^'\"]*)?['\"]";
    $anchor = '~(?:\b(?:EntryTranslation|EntryDraft)::(?!class\b)|->(?:translations|draft|toBase|getQuery|newQuery|toQuery)\(\)|->translations->|getTable\(\))~';
    $bulkInsert = '~(?:->|::)(?:insert|insertOrIgnore|insertGetId|insertUsing|upsert|updateOrInsert|fillAndInsert)\(~';
    $bulkWrite = '~->(?:update|insert\w*|upsert|updateOrInsert|updateFrom|increment|decrement)\(~';
    // a call that returns one model: an update right after it is that model's own (through the cast)
    $instanceUpdate = '~(?:->|::)(?:first\w*|find\w*|sole|create\w*|make|updateOrCreate|fresh)(\((?:[^()]++|(?1))*\))\??->update\(~';
    // the last call of a right-hand side that still holds a builder (not a model, rows or a value)
    $builderEnd = '~(?:->|::)(?:query|newQuery|toQuery|toBase|getQuery|translations|draft|where\w*|orWhere\w*|orderBy\w*|latest|oldest|limit|take|skip|offset|with\w*|join\w*|select\w*|has|doesntHave|lockForUpdate|sharedLock)(\((?:[^()]++|(?1))*\))$~';
    // SQL handed to the connection: DB::…, or a connection's own update/insert given a string
    $rawCall = '~\bDB::(?:update|insert|statement|unprepared|affectingStatement)\(|->(?:statement|unprepared|affectingStatement)\(|->(?:update|insert)\((?:[\'"]|<<<)~';
    // a write keyword not followed by a written table name: the table is computed (sprintf, a variable…)
    $computedTable = '~\b(?:update|insert\s+into|merge\s+into|copy)\s+(?:only\s+)?(?!(?:public\.)?"?[a-z_])~i';
    // …that writes a body: names the column, or computes the column too
    $bodyColumn = '~\b(?:body|content)\b|\bset\s+(?!"?[a-z_])~i';
    $namesTheTables = preg_match('~\b(?:EntryTranslation|EntryDraft)\b|'.$tables.'~', $source) === 1;

    /** @var array<string, true> $builders variables holding a builder of the two tables, in the current function */
    $builders = [];
    $violations = [];
    $check = function (string $code, string $strings, int $line) use (&$violations, &$builders, $tables, $columns, $anchor, $bulkInsert, $bulkWrite, $instanceUpdate, $builderEnd, $rawCall, $computedTable, $bodyColumn, $namesTheTables): void {
        if ($code === '') {
            return;
        }
        $variables = $builders === [] ? null : '~\$(?:'.implode('|', array_keys($builders)).')\??->~';
        $anchored = preg_match($anchor, $code) === 1 || ($variables !== null && preg_match($variables, $code) === 1);
        $bulkUpdate = preg_match_all('~->update\(~', $code) > preg_match_all($instanceUpdate, $code);
        $computedKey = false;
        if ($bulkUpdate && preg_match('~->update\((.)(.*)$~', $code, $call) === 1) {
            // the column names of the update: a non-literal one may be the body (a string argument is
            // SQL handed to a connection, which the raw SQL rules read)
            $computedKey = $call[1] === '['
                ? preg_match_all('~(?:\[|,)((?:\'[^\']*\'|"[^"]*"|[^,\[\]\'"=])+)=>~', '['.$call[2], $keys) > 0
                    && array_filter($keys[1], fn (string $key): bool => preg_match('~^([\'"]).*\1$~', $key) !== 1) !== []
                : ! in_array($call[1], ['\'', '"', '<'], true);
        }
        $rules = [
            'raw SQL write' => preg_match('~\b(?:update|insert\s+into|merge\s+into|copy)\s+(?:only\s+)?(?:public\.)?"?'.$tables.'\b~i', $strings) === 1,
            'raw SQL write to a computed table' => $namesTheTables && preg_match($rawCall, $code) === 1
                && ($strings === '' || (preg_match($computedTable, $strings) === 1 && preg_match($bodyColumn, $strings) === 1)),
            'query-builder write on the table' => preg_match('~(?:table|from|into)\([\'"](?:public\.)?'.$tables.'\b~', $code) === 1 && preg_match($bulkWrite, $code) === 1,
            'bulk insert/upsert on the model' => $anchored && preg_match($bulkInsert, $code) === 1,
            'bulk update of the body on the model' => $anchored && $bulkUpdate && preg_match('~'.$columns.'~', $code) === 1,
            'bulk update of a computed column on the model' => $anchored && $computedKey,
            'raw attributes' => str_contains($code, 'setRawAttributes(') && preg_match('~'.$columns.'~', $code) === 1,
            'JSON-path key' => preg_match("~['\"](?:body|content)->~", $code) === 1,
            'forceFill with raw JSON' => str_contains($code, 'forceFill(') && str_contains($code, 'json_encode(') && preg_match('~'.$columns.'~', $code) === 1,
        ];
        foreach ($rules as $rule => $hit) {
            if ($hit) {
                $violations[] = "{$rule} @ {$line}";
            }
        }

        // `$q = <builder of the two tables>` makes $q one for the statements after it
        if (preg_match('~^\$(\w+)=(?![=>])(.+)$~s', $code, $assignment) === 1) {
            if ($anchored && preg_match($builderEnd, $assignment[2]) === 1) {
                $builders[$assignment[1]] = true;
            } else {
                unset($builders[$assignment[1]]);
            }
        }
    };

    // One frame per statement list: the file, and each closure body inside a call. A frame's
    // `depth` counts the brackets open in its current statement.
    $frames = [['code' => '', 'strings' => '', 'line' => 1, 'depth' => 0]];
    $braces = [];
    $function = false;
    foreach (token_get_all($source) as $token) {
        [$id, $text, $at] = is_array($token) ? $token : [null, $token, null];
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML], true)) {
            continue;
        }
        // a named function starts a new scope for kept builders; a closure keeps the outer one
        if ($function && $text !== '&') {
            if ($id === T_STRING) {
                $builders = [];
            }
            $function = false;
        }
        $function = $function || $id === T_FUNCTION;
        $frame = &$frames[array_key_last($frames)];
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $braces[] = 'interpolation';
        } elseif ($id === null && $text === '{') {
            if ($frame['depth'] > 0) {
                $braces[] = 'frame';
                $frame['code'] .= '{';
                unset($frame);
                $frames[] = ['code' => '', 'strings' => '', 'line' => $at ?? 1, 'depth' => 0];

                continue;
            }
            $braces[] = 'block';
            $check($frame['code'], $frame['strings'], $frame['line']);
            $frame['code'] = $frame['strings'] = '';
            unset($frame);

            continue;
        } elseif ($id === null && $text === '}') {
            $brace = array_pop($braces) ?? 'block';
            if ($brace !== 'interpolation') {
                $check($frame['code'], $frame['strings'], $frame['line']);
                $frame['code'] = $frame['strings'] = '';
                unset($frame);
                if ($brace === 'frame' && count($frames) > 1) {
                    array_pop($frames);
                    $frames[array_key_last($frames)]['code'] .= '}';
                }

                continue;
            }
        } elseif ($id === null && $text === ';' && $frame['depth'] === 0) {
            $check($frame['code'], $frame['strings'], $frame['line']);
            $frame['code'] = $frame['strings'] = '';
            unset($frame);

            continue;
        } elseif (($id === null && ($text === '(' || $text === '[')) || $id === T_ATTRIBUTE) {
            $frame['depth']++;
        } elseif ($id === null && ($text === ')' || $text === ']')) {
            $frame['depth'] = max(0, $frame['depth'] - 1);
        }
        if ($frame['code'] === '' && $at !== null) {
            $frame['line'] = $at;
        }
        $frame['code'] .= $text;
        if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            // each piece apart, so a table spliced in between two pieces leaves a visible gap
            $frame['strings'] .= "\x01".$text;
        }
        unset($frame);
    }
    while ($frames !== []) {
        $frame = array_pop($frames);
        $check($frame['code'], $frame['strings'], $frame['line']);
    }

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
