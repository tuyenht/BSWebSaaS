<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Under construction" page for admin sections whose real UI is not built yet (e.g.
 * taxonomies, menu builder). Keeps the nav link live inside the shared admin shell so
 * there is no dead-end; the real page replaces this route later. Dataless — access is gated
 * by the route's `can:` middleware, so only the module's permitted roles reach the stub.
 */
#[Layout('admin::layouts.app')]
final class Placeholder extends Component
{
    public function render(): View
    {
        return view('admin::livewire.placeholder');
    }
}
