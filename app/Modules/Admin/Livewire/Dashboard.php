<?php

namespace App\Modules\Admin\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin landing. Auth-gated by the route; the dynamic nav (content_sections ∩
 * permissions) is supplied to the layout by AdminNav, so the shell shows only what
 * the user is entitled to and permitted for.
 */
#[Layout('admin::layouts.app')]
final class Dashboard extends Component
{
    public function render(): View
    {
        return view('admin::livewire.dashboard');
    }
}
