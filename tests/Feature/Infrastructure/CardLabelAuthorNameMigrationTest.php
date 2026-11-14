<?php

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * The migration of the two typed per-language texts: a category's card label
 * (taxonomy_translations.card_label) and a post's byline (entry_translations.author_name). up()
 * adds nullable varchars with no default the app role may write, and runs twice; down() never
 * drops text an editor typed: while a row holds one it refuses, naming the count, and drops
 * nothing.
 */

/**
 * The two columns as the catalog describes them, read on the privileged connection.
 *
 * @return list<object{table_name: string, column_name: string, data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}>
 */
function labelBylineColumns(): array
{
    /** @var list<object{table_name: string, column_name: string, data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}> */
    return DB::connection('pgsql_admin')->select(
        "select table_name, column_name, data_type, character_maximum_length, is_nullable, column_default from information_schema.columns
         where table_schema = current_schema()
           and ((table_name = 'taxonomy_translations' and column_name = 'card_label') or (table_name = 'entry_translations' and column_name = 'author_name'))
         order by table_name"
    );
}

function labelBylineMigration(): Migration
{
    return require database_path('migrations/2060_01_01_000000_add_card_label_and_author_name_to_translations.php');
}

/** Runs $step on the privileged connection, as migrations run. */
function asMigrator(Closure $step): mixed
{
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        return $step();
    } finally {
        DB::setDefaultConnection($original);
    }
}

it('adds both texts as nullable varchars with no default that the app may write', function (): void {
    $columns = labelBylineColumns();

    expect(array_map(fn (object $column): array => [$column->table_name, $column->column_name, $column->data_type, $column->character_maximum_length, $column->is_nullable, $column->column_default], $columns))
        ->toBe([
            ['entry_translations', 'author_name', 'character varying', 80, 'YES', null],
            ['taxonomy_translations', 'card_label', 'character varying', 40, 'YES', null],
        ]);

    $granted = DB::connection('pgsql_admin')->selectOne(
        "select has_column_privilege('app_user', 'taxonomy_translations', 'card_label', 'UPDATE') as labels,
                has_column_privilege('app_user', 'entry_translations', 'author_name', 'UPDATE') as bylines"
    );
    expect($granted?->labels)->toBeTrue()->and($granted?->bylines)->toBeTrue();

    // A second up() on a migrated database changes nothing.
    asMigrator(fn () => labelBylineMigration()->up());
    expect(labelBylineColumns())->toEqual($columns);
});

it('refuses to roll back while a card label or a byline is set, and drops nothing', function (string $which): void {
    StorefrontCards::reset();
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($which): void {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Bài', 'bai');
        $which === 'label'
            ? TaxonomyTranslation::query()->where('slug', 'tin-tuc')->update(['card_label' => 'Bản tin'])
            : EntryTranslation::query()->where('entry_id', $post->id)->update(['author_name' => 'Phòng Kỹ thuật']);
    });

    $column = $which === 'label' ? 'taxonomy_translations.card_label' : 'entry_translations.author_name';
    expect(fn () => asMigrator(fn () => labelBylineMigration()->down()))
        ->toThrow(RuntimeException::class, "Refused: 1 row(s) of {$column} still hold text an editor typed.")
        ->and(labelBylineColumns())->toHaveCount(2);
})->with(['label', 'byline']);

it('drops both columns when nothing is set, and up() puts them back', function (): void {
    StorefrontCards::reset();
    $migration = labelBylineMigration();

    try {
        asMigrator(function () use ($migration): void {
            $migration->down();
            $migration->down();
        });
        $dropped = labelBylineColumns();
    } finally {
        // Whatever failed above, the schema is left as it was found.
        asMigrator(fn () => $migration->up());
    }

    expect($dropped)->toBe([])->and(labelBylineColumns())->toHaveCount(2);
})->skip(getenv('CI') !== 'true', 'drops and re-adds columns: runs on CI\'s own database only');
