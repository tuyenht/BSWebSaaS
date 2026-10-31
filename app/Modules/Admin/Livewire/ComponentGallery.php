<?php

namespace App\Modules\Admin\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dev-only showcase of the admin design-system primitives (button/card/badge/input/table)
 * rendered in the real shell — for visual fidelity review against Velzon. Never reachable
 * in production: mount() aborts. Route is auth + tenant-scoped like the rest of the admin.
 */
#[Layout('admin::layouts.app')]
final class ComponentGallery extends Component
{
    /** Live values for the list-tools sample, so its radios and checkboxes really bind. */
    public string $galleryStatus = 'live';

    /** @var list<string> */
    public array $galleryKinds = [];

    public string $gallerySearch = '';

    public string $galleryGroup = 'all';

    public string $gallerySort = 'name';

    /** The Sort menu sample: one of the shown orders. */
    public function setGallerySort(string $key): void
    {
        $this->gallerySort = in_array($key, ['name', 'size', 'new'], true) ? $key : 'name';
    }

    public function clearGalleryFilters(): void
    {
        $this->galleryStatus = 'live';
        $this->galleryKinds = [];
        $this->galleryGroup = 'all';
    }

    public function mount(): void
    {
        abort_if(app()->isProduction(), 404);
    }

    public function render(): View
    {
        return view('admin::livewire.component-gallery');
    }
}
