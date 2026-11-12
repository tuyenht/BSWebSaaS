{{--
    A dashboard card whose read failed: plain words and "Thử lại" (the next render reads it again).
    While the retry runs, the card shows a skeleton in place of the message.
--}}
<div class="p-4">
    <div wire:loading.remove wire:target="retry('{{ $card }}')" class="flex flex-col items-start gap-2.5 py-2">
        <p role="alert" class="flex items-start gap-1.5 text-small leading-normal text-danger-text">
            <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-[calc(1rem+var(--user-text-boost))] shrink-0" />
            <span>{{ __('dash.card_error') }}</span>
        </p>
        <button type="button" wire:click="retry('{{ $card }}')" class="{{ $btn }}">
            <x-bs-admin::icon name="ri-refresh" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ __('dash.retry') }}</span>
        </button>
    </div>
    <div wire:loading.flex wire:target="retry('{{ $card }}')" aria-busy="true" class="flex-col gap-2.5">
        <span role="status" class="sr-only">{{ __('dash.loading', ['card' => $label]) }}</span>
        <span class="h-[1.125rem] w-1/2 animate-pulse rounded bg-surface-hover"></span>
        <span class="h-3.5 w-[90%] animate-pulse rounded bg-surface-hover"></span>
        <span class="h-3.5 w-3/4 animate-pulse rounded bg-surface-hover"></span>
    </div>
</div>
