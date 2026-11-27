<?php

use Illuminate\Support\Facades\File;

/*
 * A media file goes to the Trash and back only through Media\Actions\TrashMedia, and is deleted for
 * good only through Media\Actions\PurgeMedia: they move the library's storage totals (`media.bytes`,
 * `media.trash_bytes`, MediaStorage) in the same transaction. A soft delete, restore or force delete
 * of a media row anywhere else would leave the totals (and «Thùng rác đang giữ …») wrong until the
 * nightly recount, so it fails here.
 *
 * The scan reads every file that uses the media model (or lives in the Media module) with PHP's own
 * tokenizer, finds each call of delete/restore/forceDelete (and their Quietly forms, `each->` and
 * `::destroy`) without arguments (a disk's delete($key) takes one), and names it by what the call
 * chain starts from: `$product->restore`, `Mediable->delete` for `Mediable::query()->…->delete()`.
 * Each one must be a chokepoint's own or listed below as another model's; anything else — a media
 * row, or a new call on another model, which then has to be listed — fails.
 */

/** The chokepoints: the file => the calls it may make on a media row. */
const MEDIA_LIFECYCLE_CHOKEPOINTS = [
    'app/Modules/Media/Actions/TrashMedia.php' => ['$media->delete', '$media->restore'],
    'app/Modules/Media/Actions/PurgeMedia.php' => ['$media->forceDelete'],
];

/** Files that use the media model and delete or restore something else: the file => call => what it is. */
const MEDIA_LIFECYCLE_OTHER_MODELS = [
    'app/Modules/Admin/Livewire/Catalog/Editor.php' => ['$product->restore' => 'a product', '$live->delete' => 'a product draft’s live copy'],
    'app/Modules/Admin/Livewire/Catalog/Index.php' => ['$product->delete' => 'a product', '$product->restore' => 'a product'],
    'app/Modules/Admin/Livewire/Entries/Editor.php' => ['$live->delete' => 'an entry draft’s live copy'],
    'app/Modules/Admin/Livewire/Media/Library.php' => ['$row->delete' => 'a media folder'],
    'app/Modules/Admin/Support/Settings/BranchList.php' => ['$rows->delete' => 'media attachments'],
    'app/Modules/Admin/Support/Settings/FloatingContactInput.php' => ['$rows->delete' => 'media attachments'],
    'app/Modules/Admin/Support/Settings/HeadingDecorInput.php' => ['Mediable->delete' => 'media attachments'],
    'app/Modules/Admin/Support/Settings/ResetSettingsSection.php' => ['$row->delete' => 'a site setting', 'SiteSetting->delete' => 'a site setting'],
    'app/Modules/Admin/Support/Settings/SetHeadingDecor.php' => ['$row->delete' => 'a site setting'],
    'app/Modules/Catalog/Support/ProductGallery.php' => ['ProductImage->delete' => 'a product’s gallery rows'],
    'app/Modules/Cms/Support/BlockMediaUsage.php' => ['$rows->delete' => 'media attachments', 'Mediable->delete' => 'media attachments'],
    'app/Modules/Cms/Support/EntryCoverUsage.php' => ['$rows->delete' => 'media attachments'],
    'app/Modules/Media/Jobs/DeleteLargeMediaFolder.php' => ['$row->delete' => 'a media folder'],
    'app/Modules/Media/Observers/MediaObserver.php' => ['Mediable->delete' => 'media attachments'],
    'app/Modules/Provisioning/Actions/PromoteAnVinhProductPostsToCatalog.php' => ['Redirect->forceDelete' => 'redirects it replaces', '$entry->delete' => 'a post it promotes', 'SiteRoute->delete' => 'the post’s routes'],
    'app/Modules/Provisioning/ContentPack/Applier.php' => ['SiteSetting->delete' => 'a permalink setting', '$section->delete' => 'a content section', '$entry->restore' => 'an entry staff put in the trash', 'MenuItem->forceDelete' => 'menu items of a menu it rebuilds'],
];

/**
 * Every argument-less delete/restore/forceDelete call in $code, as "root->method" with its line.
 *
 * @return list<array{0: string, 1: int}>
 */
function mediaLifecycleCalls(string $code): array
{
    $methods = ['delete', 'restore', 'forcedelete', 'deletequietly', 'restorequietly', 'forcedeletequietly'];
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $calls = [];
    foreach ($tokens as $i => $token) {
        $name = strtolower($token->text);
        $prev = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        if ($prev === null || ! $token->is(T_STRING)) {
            continue;
        }
        $static = $prev->is(T_DOUBLE_COLON) && in_array($name, ['destroy', 'forcedestroy'], true);
        $method = $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && in_array($name, $methods, true)
            && $next?->text === '(' && ($tokens[$i + 2] ?? null)?->text === ')';
        if (! $static && ! $method) {
            continue;
        }
        $calls[] = [mediaLifecycleRoot($tokens, $i - 1).'->'.$token->text, $token->line];
    }

    return $calls;
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

/** @return list<string> the media-using app files, relative to the base path */
function mediaLifecycleFiles(): array
{
    $files = [];
    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        $code = $file->getContents();
        if (str_starts_with($path, 'app/Modules/Media/') || str_contains($code, 'App\\Modules\\Media\\Models\\Media;')
            || str_contains($code, '\\App\\Modules\\Media\\Models\\Media::')) {
            $files[] = $path;
        }
    }

    return $files;
}

test('media rows go to the Trash and back only in TrashMedia, and are deleted for good only in PurgeMedia', function (): void {
    $offenders = [];
    foreach (mediaLifecycleFiles() as $path) {
        $allowed = [...(MEDIA_LIFECYCLE_CHOKEPOINTS[$path] ?? []), ...array_keys(MEDIA_LIFECYCLE_OTHER_MODELS[$path] ?? [])];
        foreach (mediaLifecycleCalls((string) file_get_contents(base_path($path))) as [$call, $line]) {
            if (! in_array($call, $allowed, true)) {
                $offenders[] = "{$path}:{$line}  {$call}";
            }
        }
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
]);

test('every listed file still exists and still makes each listed call', function (): void {
    foreach ([...MEDIA_LIFECYCLE_CHOKEPOINTS, ...array_map(array_keys(...), MEDIA_LIFECYCLE_OTHER_MODELS)] as $path => $calls) {
        $found = array_column(mediaLifecycleCalls((string) file_get_contents(base_path($path))), 0);
        foreach ($calls as $call) {
            expect($found)->toContain($call);
        }
    }
});
