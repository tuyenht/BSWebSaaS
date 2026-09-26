---
paths:
  - "app/Modules/Admin/Theme/AdminChromeResolver.php"
  - "app/Modules/Admin/Http/Controllers/ThemePreferenceController.php"
  - "app/Modules/Admin/Livewire/AppearanceSettings.php"
---

# Mutation baseline (manual tier-2 gate, CLAUDE.md §6)

Changing a class on this list → run the command below and paste the output:

```bash
docker compose run --rm --no-deps app php -d pcov.enabled=1 vendor/bin/pest --mutate \
  --class="App\Modules\Admin\Theme\AdminChromeResolver,App\Modules\Admin\Http\Controllers\ThemePreferenceController,App\Modules\Admin\Livewire\AppearanceSettings" \
  --covered-only --min=75
```

- pcov ships in the image, off by default; the command enables it for this run only.
- Widen the class list (and this file's `paths:`) and raise `--min` over time — never lower it.
