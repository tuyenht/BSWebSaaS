<?php

use Illuminate\Support\Facades\File;

/*
 * A media file goes to the Trash and back only through Media\Actions\TrashMedia, and is deleted for
 * good only through Media\Actions\PurgeMedia: they move the library's storage totals (`media.bytes`,
 * `media.trash_bytes`, MediaStorage) in the same transaction. A soft delete, restore or force delete
 * of a media row anywhere else would leave the totals (and «Thùng rác đang giữ …») wrong until the
 * nightly recount, so it fails here.
 *
 * The scan reads every file that can reach the media model (the Media module, or any file naming
 * Models\Media: imported, aliased, in a grouped import or written out) with PHP's own tokenizer,
 * finds each call of delete/restore/forceDelete (and their Quietly forms, `each->` and `::destroy`)
 * without arguments (a disk's delete($key) takes one), each write that sets `deleted_at` itself
 * (`->update(['deleted_at' => …])`, forceFill, fill, `->deleted_at = …`), and names it by the
 * function it sits in and what the call chain starts from: `trash: $product->restore`,
 * `sync: Mediable->delete` for `Mediable::query()->…->delete()`. Each one must be a chokepoint's own
 * or listed below as another model's, and an entry covers exactly the calls it counts, in that
 * function: a media row deleted anywhere else, even beside a listed call on another model with the
 * same variable name, fails.
 */

/** The chokepoints: the file => "function: call" it may make on a media row, once each. */
const MEDIA_LIFECYCLE_CHOKEPOINTS = [
    'app/Modules/Media/Actions/TrashMedia.php' => ['trash: $media->delete', 'restore: $media->restore'],
    'app/Modules/Media/Actions/PurgeMedia.php' => ['deleteTrashed: $media->forceDelete'],
];

/**
 * Files that reach the media model and delete or restore something else: the file => "function:
 * call" => what it is, with "×N" at the end when that function makes the call N times.
 */
const MEDIA_LIFECYCLE_OTHER_MODELS = [
    'app/Modules/Admin/Livewire/Catalog/Editor.php' => ['bringBack: $product->restore' => 'a product', 'trash: $live->delete' => 'a product draft’s live copy'],
    'app/Modules/Admin/Livewire/Catalog/Index.php' => [
        'trash: $product->delete' => 'a product', 'restore: $product->restore' => 'a product',
        'undoLast: $product->restore' => 'a product', 'undoLast: $product->delete' => 'a product',
        'bulkTrash: $product->delete' => 'a product', 'bulkRestore: $product->restore' => 'a product',
        'undoBulk: $product->restore' => 'a product', 'undoBulk: $product->delete' => 'a product',
    ],
    'app/Modules/Admin/Livewire/Entries/Editor.php' => ['trash: $live->delete' => 'an entry draft’s live copy'],
    'app/Modules/Admin/Livewire/Media/Library.php' => ['deleteFolder: $row->delete' => 'a media folder'],
    'app/Modules/Admin/Support/Settings/BranchList.php' => ['syncUsage: $rows->delete' => 'media attachments'],
    'app/Modules/Admin/Support/Settings/FloatingContactInput.php' => ['syncIconUsage: $rows->delete' => 'media attachments'],
    'app/Modules/Admin/Support/Settings/HeadingDecorInput.php' => ['syncMaskUsage: Mediable->delete' => 'media attachments'],
    'app/Modules/Admin/Support/Settings/ResetSettingsSection.php' => ['reset: $row->delete' => 'a site setting', 'undo: SiteSetting->delete' => 'a site setting'],
    'app/Modules/Admin/Support/Settings/SetHeadingDecor.php' => ['handle: $row->delete' => 'a site setting'],
    'app/Modules/Catalog/Support/ProductGallery.php' => ['sync: ProductImage->delete' => 'a product’s gallery rows'],
    'app/Modules/Cms/Support/BlockMediaUsage.php' => ['sync: $rows->delete' => 'media attachments', 'forget: Mediable->delete' => 'media attachments'],
    'app/Modules/Cms/Support/EntryCoverUsage.php' => ['sync: $rows->delete' => 'media attachments ×3'],
    'app/Modules/Media/Jobs/DeleteLargeMediaFolder.php' => ['deleteRowIfSmall: $row->delete' => 'a media folder'],
    'app/Modules/Media/Observers/MediaObserver.php' => ['forceDeleting: Mediable->delete' => 'media attachments'],
    'app/Modules/Provisioning/Actions/PromoteAnVinhProductPostsToCatalog.php' => [
        'bridgeRetiredPath: Redirect->forceDelete' => 'redirects it replaces', 'migrateOne: $entry->delete' => 'a post it promotes',
        'migrateOne: SiteRoute->delete' => 'the post’s routes',
    ],
    'app/Modules/Provisioning/ContentPack/Applier.php' => [
        'removeSection: SiteSetting->delete' => 'a permalink setting', 'removeSection: $section->delete' => 'a content section',
        'entry: $entry->restore' => 'an entry staff put in the trash', 'menu: MenuItem->forceDelete' => 'menu items of a menu it rebuilds',
    ],
];

/**
 * Every call in $code that can put a media row into the Trash, take it out or delete it for good, as
 * "root->method" with its line and the named function it sits in ('' outside one): an argument-less
 * delete/restore/forceDelete (and Quietly forms, `each->`), `::destroy`, a write method given a
 * 'deleted_at' key (`->update(['deleted_at' => …])`, forceFill, fill, upsert) and an assignment to
 * `->deleted_at`.
 *
 * @return list<array{0: string, 1: int, 2: string}>
 */
function mediaLifecycleCalls(string $code): array
{
    $methods = ['delete', 'restore', 'forcedelete', 'deletequietly', 'restorequietly', 'forcedeletequietly'];
    $writes = ['update', 'updatequietly', 'updateorfail', 'forcefill', 'fill', 'upsert'];
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $calls = [];
    $in = '';
    foreach ($tokens as $i => $token) {
        $name = strtolower($token->text);
        $prev = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        if ($prev === null || ! $token->is(T_STRING)) {
            continue;
        }
        if ($prev->is(T_FUNCTION)) {
            $in = $token->text; // a named function or method; a closure keeps the one around it

            continue;
        }
        $arrow = $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
        $call = match (true) {
            $prev->is(T_DOUBLE_COLON) && in_array($name, ['destroy', 'forcedestroy'], true) => $token->text,
            $arrow && in_array($name, $methods, true) && $next?->text === '(' && ($tokens[$i + 2] ?? null)?->text === ')' => $token->text,
            $arrow && in_array($name, $writes, true) && $next?->text === '(' && mediaLifecycleArgsName($tokens, $i + 1, 'deleted_at') => $token->text.'(deleted_at)',
            $arrow && $name === 'deleted_at' && $next?->text === '=' => 'deleted_at=',
            default => null,
        };
        if ($call !== null) {
            $calls[] = [mediaLifecycleRoot($tokens, $i - 1).'->'.$call, $token->line, $in];
        }
    }

    return $calls;
}

/**
 * Whether the arguments opening at the bracket $open set $key: the string ('deleted_at' or
 * "deleted_at") as an array key, `=>` after it.
 *
 * @param  list<PhpToken>  $tokens
 */
function mediaLifecycleArgsName(array $tokens, int $open, string $key): bool
{
    $depth = 0;
    for ($j = $open; $j < count($tokens); $j++) {
        $depth += match ($tokens[$j]->text) {
            '(', '[' => 1, ')', ']' => -1, default => 0
        };
        if ($depth === 0) {
            return false;
        }
        if ($tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING) && trim($tokens[$j]->text, '\'"') === $key && ($tokens[$j + 1] ?? null)?->is(T_DOUBLE_ARROW)) {
            return true;
        }
    }

    return false;
}

/**
 * What the call chain ending at the operator $at starts from: a variable, or a class name.
 *
 * @param  list<PhpToken>  $tokens
 */
function mediaLifecycleRoot(array $tokens, int $at): string
{
    $root = '?';
    $j = $at - 1;
    while ($j >= 0) {
        $t = $tokens[$j];
        if ($t->text === ')' || $t->text === ']') {
            $depth = 0;
            for (; $j >= 0; $j--) {
                $depth += match ($tokens[$j]->text) {
                    ')', ']' => 1, '(', '[' => -1, default => 0
                };
                if ($depth === 0) {
                    break;
                }
            }
            $j--; // the token before the opening bracket: the callee

            continue;
        }
        if (! $t->is([T_STRING, T_VARIABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])) {
            break;
        }
        $root = $t->text;
        $before = $tokens[$j - 1] ?? null;
        if ($before === null || ! $before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
            break;
        }
        $j -= 2;
    }

    return ltrim((string) preg_replace('/^.*\\\\/', '', $root), '\\');
}

/**
 * Whether the file at $path can reach the media model: the Media module, or a file naming
 * Models\Media in any spelling (imported, aliased, grouped in `Models\{…}`, or written out).
 */
function mediaLifecycleUsesMedia(string $path, string $code): bool
{
    return str_starts_with($path, 'app/Modules/Media/')
        || preg_match('/\bModels\\\\(?:Media\b|\{[^}]*\bMedia\b)/', $code) === 1;
}

/**
 * How many times the file at $path may make each "function: call".
 *
 * @return array<string, int>
 */
function mediaLifecycleAllowed(string $path): array
{
    $allowed = array_fill_keys(MEDIA_LIFECYCLE_CHOKEPOINTS[$path] ?? [], 1);
    foreach (MEDIA_LIFECYCLE_OTHER_MODELS[$path] ?? [] as $call => $what) {
        $allowed[$call] = preg_match('/×(\d+)$/u', $what, $m) === 1 ? (int) $m[1] : 1;
    }

    return $allowed;
}

/**
 * The calls in $code (the file at $path) beyond what the file is allowed, as "path:line  function: call".
 *
 * @return list<string>
 */
function mediaLifecycleOffenders(string $path, string $code): array
{
    $left = mediaLifecycleAllowed($path);
    $offenders = [];
    foreach (mediaLifecycleCalls($code) as [$call, $line, $in]) {
        $key = "{$in}: {$call}";
        if (($left[$key] ?? 0) > 0) {
            $left[$key]--;

            continue;
        }
        $offenders[] = "{$path}:{$line}  {$key}";
    }

    return $offenders;
}

/** @return list<string> the app files that can reach the media model, relative to the base path */
function mediaLifecycleFiles(): array
{
    $files = [];
    foreach (File::allFiles(app_path()) as $file) {
        $path = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        if ($file->getExtension() === 'php' && mediaLifecycleUsesMedia($path, $file->getContents())) {
            $files[] = $path;
        }
    }

    return $files;
}

test('media rows go to the Trash and back only in TrashMedia, and are deleted for good only in PurgeMedia', function (): void {
    $offenders = [];
    foreach (mediaLifecycleFiles() as $path) {
        array_push($offenders, ...mediaLifecycleOffenders($path, (string) file_get_contents(base_path($path))));
    }

    expect($offenders)->toBe([]);
});

test('the scan finds what it must refuse', function (string $code, array $expected): void {
    expect(array_column(mediaLifecycleCalls("<?php\n".$code), 0))->toBe($expected);
})->with([
    'a soft delete' => ['$media->delete();', ['$media->delete']],
    'a restore through a chain' => ['Media::onlyTrashed()->whereKey($ids)->restore();', ['Media->restore']],
    'a mass soft delete over lines' => ["Media::query()\n    ->where('folder_id', 1)\n    ->delete();", ['Media->delete']],
    'a force delete on a nullsafe find' => ['Media::query()->find(1)?->forceDelete();', ['Media->forceDelete']],
    'a quiet one' => ['$file->deleteQuietly();', ['$file->deleteQuietly']],
    'each' => ['$rows->each->delete();', ['$rows->delete']],
    'destroy' => ['Media::destroy([1, 2]);', ['Media->destroy']],
    'a disk delete is not one' => ['$disk->delete($key); Storage::disk("r2")->delete($media->storage_key);', []],
    'a soft delete written as an update' => ["Media::query()->whereKey(\$ids)->update(['deleted_at' => now()]);", ['Media->update(deleted_at)']],
    'a restore written as an update among other columns' => ['$media->update(["folder_id" => null, "deleted_at" => null]);', ['$media->update(deleted_at)']],
    'a restore written as forceFill' => ["\$media->forceFill(['deleted_at' => null])->save();", ['$media->forceFill(deleted_at)']],
    'a restore written as an assignment' => ['$media->deleted_at = null; $media->save();', ['$media->deleted_at=']],
    'reading deleted_at is not one' => ["Media::query()->whereNull('deleted_at')->update(['folder_id' => null]); if (\$m->deleted_at === null) { \$m->update(['alt' => 'deleted_at']); }", []],
]);

test('the scan reads every file that names the media model, however it is imported', function (string $code, bool $expected): void {
    expect(mediaLifecycleUsesMedia('app/Modules/Cms/Example.php', "<?php\n".$code))->toBe($expected);
})->with([
    'an import' => ['use App\Modules\Media\Models\Media;', true],
    'an aliased import' => ['use App\Modules\Media\Models\Media as Asset;', true],
    'a grouped import' => ['use App\Modules\Media\Models\{Mediable, Media};', true],
    'a grouped import over lines' => ["use App\\Modules\\Media\\Models\\{\n    Media,\n    MediaFolder,\n};", true],
    'written out' => ['\App\Modules\Media\Models\Media::query()->first();', true],
    'another media model only' => ['use App\Modules\Media\Models\MediaFolder;', false],
    'other media models grouped' => ['use App\Modules\Media\Models\{Mediable, MediaFolder};', false],
]);

test('an aliased import is named by its alias, so its calls are refused', function (): void {
    $code = "<?php\nuse App\\Modules\\Media\\Models\\Media as Asset;\nfunction drop(): void { Asset::query()->whereKey(1)->forceDelete(); }";

    expect(mediaLifecycleOffenders('app/Modules/Cms/Example.php', $code))->toBe(['app/Modules/Cms/Example.php:3  drop: Asset->forceDelete']);
});

test('an entry covers its own function and count only, not a call of the same name elsewhere in the file', function (): void {
    $path = 'app/Modules/Admin/Livewire/Media/Library.php';
    $code = "<?php\nfunction deleteFolder(): void { \$row->delete(); }\nfunction purgeOne(): void { \$row->delete(); }\nfunction deleteFolders(): void {}\n";
    $twice = "<?php\nfunction deleteFolder(): void {\n \$row->delete();\n \$row->delete();\n}\n";

    expect(mediaLifecycleOffenders($path, $code))->toBe(["{$path}:3  purgeOne: \$row->delete"])
        ->and(mediaLifecycleOffenders($path, $twice))->toBe(["{$path}:4  deleteFolder: \$row->delete"])
        ->and(mediaLifecycleOffenders('app/Modules/Cms/Support/EntryCoverUsage.php', "<?php\nfunction sync(): void { \$rows->delete(); \$rows->delete(); \$rows->delete(); \$rows->delete(); }"))
        ->toBe(['app/Modules/Cms/Support/EntryCoverUsage.php:2  sync: $rows->delete']);
});

test('every listed file still exists and still makes each listed call, as many times as listed', function (): void {
    foreach (array_keys([...MEDIA_LIFECYCLE_CHOKEPOINTS, ...MEDIA_LIFECYCLE_OTHER_MODELS]) as $path) {
        $found = array_count_values(array_map(
            fn (array $call): string => "{$call[2]}: {$call[0]}",
            mediaLifecycleCalls((string) file_get_contents(base_path($path))),
        ));
        foreach (mediaLifecycleAllowed($path) as $call => $times) {
            expect($found[$call] ?? 0)->toBe($times, "{$path}  {$call}");
        }
    }
});
