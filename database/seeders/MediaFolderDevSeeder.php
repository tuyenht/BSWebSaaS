<?php

namespace Database\Seeders;

use App\Modules\Media\Models\MediaFolder;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dev convenience: starter folders so the picker sidebar has content before the create-folder UI
 * ships. Run by hand (site 1): php artisan db:seed --class=MediaFolderDevSeeder
 * Deliberately NOT wired into DatabaseSeeder — production folders are user-created.
 */
final class MediaFolderDevSeeder extends Seeder
{
    public function run(): void
    {
        app(Tenancy::class)->enter(1, function (): void {
            foreach (['Sản phẩm', 'Bài viết', 'Banner'] as $position => $name) {
                MediaFolder::query()->firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'position' => $position],
                );
            }
        });
    }
}
