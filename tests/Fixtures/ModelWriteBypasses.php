<?php

namespace Tests\Fixtures;

/**
 * The writes to a set of columns that skip their model (its casts and events), read from a PHP source
 * statement by statement from PHP tokens: query-builder inserts/updates on the tables (DB::table, raw
 * SQL, a model's or a relation's bulk update/insert/upsert, toBase()/getQuery()/newQuery()/toQuery(),
 * the table name read from the model), raw attributes and JSON-path keys; optionally bulk deletes and
 * quiet saves. A builder kept in a variable counts as the builder for the rest of its function, a bulk
 * update whose column is not a literal counts as a write of the columns, and raw SQL whose table is
 * computed counts as a write to the tables in a file that names them. An update called on one model
 * (after first…/find…/create…) is the instance update, which does go through the model.
 *
 * A coarse tripwire (a closure inside a call stays in its statement; its own body is read as statements
 * of its own): it sees the literal spellings only. Each chokepoint test that uses it proves it catches
 * each banned form, so it cannot pass by seeing nothing (PageBodyWriteChokepointTest,
 * ProductSearchWordsChokepointTest).
 */
final class ModelWriteBypasses
{
    /**
     * @param  string  $tables  regex group of the table names, e.g. '(?:entry_translations|entry_drafts)'
     * @param  string  $columns  regex alternation of the guarded columns, e.g. 'body|content'
     * @param  string  $models  regex alternation of the model classes
     * @param  string  $relations  regex alternation of the relation methods that return a builder of them
     * @param  string  $collection  the relation read as a loaded collection (`->translations->`)
     * @param  bool  $columnAware  a raw SQL or table write counts only when it names the columns (or hides its values)
     * @param  string|null  $deleteAnchor  regex of a builder whose bulk delete skips the events, when that matters (in a file that names the models)
     * @param  bool  $quietSaves  a quiet save (…Quietly, withoutEvents) in a file that names the models counts
     */
    public function __construct(
        public readonly string $tables,
        public readonly string $columns,
        public readonly string $models,
        public readonly string $relations,
        public readonly string $collection,
        public readonly bool $columnAware = false,
        public readonly ?string $deleteAnchor = null,
        public readonly bool $quietSaves = false,
    ) {}

    /**
     * The banned writes in a PHP source, as "rule @ line".
     *
     * @return list<string>
     */
    public function find(string $source): array
    {
        $spec = $this;
        $tables = $spec->tables;
        $columns = "['\"](?:".$spec->columns.")(?:->[^'\"]*)?['\"]";
        $anchor = '~(?:\b(?:'.$spec->models.')::(?!class\b)|->(?:'.$spec->relations.'|toBase|getQuery|newQuery|toQuery)\(\)|->'.$spec->collection.'->|getTable\(\))~';
        $bulkInsert = '~(?:->|::)(?:insert|insertOrIgnore|insertGetId|insertUsing|upsert|updateOrInsert|fillAndInsert)\(~';
        $bulkWrite = '~->(?:update|insert\w*|upsert|updateOrInsert|updateFrom|increment|decrement)\(~';
        // a call that returns one model: an update right after it is that model's own (through the cast)
        $instanceUpdate = '~(?:->|::)(?:first\w*|find\w*|sole|create\w*|make|updateOrCreate|fresh)(\((?:[^()]++|(?1))*\))\??->update\(~';
        // the last call of a right-hand side that still holds a builder (not a model, rows or a value)
        $builderEnd = '~(?:->|::)(?:query|newQuery|toQuery|toBase|getQuery|'.$spec->relations.'|where\w*|orWhere\w*|orderBy\w*|latest|oldest|limit|take|skip|offset|with\w*|join\w*|select\w*|has|doesntHave|lockForUpdate|sharedLock)(\((?:[^()]++|(?1))*\))$~';
        // SQL handed to the connection: DB::…, or a connection's own update/insert given a string
        $rawCall = '~\bDB::(?:update|insert|statement|unprepared|affectingStatement)\(|->(?:statement|unprepared|affectingStatement)\(|->(?:update|insert)\((?:[\'"]|<<<)~';
        // a write keyword not followed by a written table name: the table is computed (sprintf, a variable…)
        $computedTable = '~\b(?:update|insert\s+into|merge\s+into|copy)\s+(?:only\s+)?(?!(?:public\.)?"?[a-z_])~i';
        // …that writes a body: names the column, or computes the column too
        $bodyColumn = '~\b(?:'.$spec->columns.')\b|\bset\s+(?!"?[a-z_])~i';
        $namesTheTables = preg_match('~\b(?:'.$spec->models.')\b|'.$tables.'~', $source) === 1;

        /** @var array<string, true> $builders variables holding a builder of the two tables, in the current function */
        $builders = [];
        $violations = [];
        $check = function (string $code, string $strings, int $line) use (&$violations, &$builders, $spec, $tables, $columns, $anchor, $bulkInsert, $bulkWrite, $instanceUpdate, $builderEnd, $rawCall, $computedTable, $bodyColumn, $namesTheTables): void {
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
            // with columnAware, a write counts when it names the columns or does not write its values out
            $namesColumns = ! $spec->columnAware || preg_match('~'.$columns.'~', $code) === 1 || preg_match('~->(?:insert\w*|upsert|update|updateOrInsert)\(\s*\$~', $code) === 1;
            $rules = [
                'raw SQL write' => preg_match('~\b(?:update|insert\s+into|merge\s+into|copy)\s+(?:only\s+)?(?:public\.)?"?'.$tables.'\b~i', $strings) === 1
                    && (! $spec->columnAware || preg_match($bodyColumn, $strings) === 1),
                'raw SQL write to a computed table' => $namesTheTables && preg_match($rawCall, $code) === 1
                    && ($strings === '' || (preg_match($computedTable, $strings) === 1 && preg_match($bodyColumn, $strings) === 1)),
                'query-builder write on the table' => preg_match('~(?:table|from|into)\([\'"](?:public\.)?'.$tables.'\b~', $code) === 1 && preg_match($bulkWrite, $code) === 1 && $namesColumns,
                'bulk insert/upsert on the model' => $anchored && preg_match($bulkInsert, $code) === 1,
                'bulk update of the column on the model' => $anchored && $bulkUpdate && preg_match('~'.$columns.'~', $code) === 1,
                'bulk update of a computed column on the model' => $anchored && $computedKey,
                'raw attributes' => str_contains($code, 'setRawAttributes(') && preg_match('~'.$columns.'~', $code) === 1,
                'JSON-path key' => preg_match("~['\"](?:".$spec->columns.')->~', $code) === 1,
                'forceFill with raw JSON' => str_contains($code, 'forceFill(') && str_contains($code, 'json_encode(') && preg_match('~'.$columns.'~', $code) === 1,
                'bulk delete on the model' => $spec->deleteAnchor !== null && $namesTheTables && preg_match($spec->deleteAnchor, $code) === 1
                    && preg_match_all('~->(?:delete|forceDelete)\(~', $code) > preg_match_all(str_replace('->update\\(', '->(?:delete|forceDelete)\\(', $instanceUpdate), $code),
                'save without events' => $spec->quietSaves && $namesTheTables && preg_match('~(?:->|::)(?:\w+Quietly|withoutEvents|unsetEventDispatcher)\(~', $code) === 1,
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
}
