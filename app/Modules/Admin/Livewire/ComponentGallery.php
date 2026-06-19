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
    public function mount(): void
    {
        abort_if(app()->isProduction(), 404);
    }

    public function render(): View
    {
        return view('admin::livewire.component-gallery');
    }
}
