<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * A settings section's one message under the save bar (<x-bs-admin::toast>): its text, its tone,
 * and a sequence number so closing an older message never closes a newer one. `undo` is what
 * «Khôi phục mặc định» removed (RestoresSettingsSection), offered as «Hoàn tác» on that message
 * only: any later message, or closing it, takes the offer away.
 */
trait ShowsSettingsToast
{
    public string $toast = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /**
     * What «Khôi phục mặc định» removed, for «Hoàn tác» (kept on the server side of the component).
     *
     * @var array<array-key, mixed>|null
     */
    #[Locked]
    public ?array $undo = null;

    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
            $this->undo = null;
        }
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
        $this->undo = null;
    }
}
