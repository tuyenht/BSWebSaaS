{{--
    A dashboard card whose read failed: plain words and "Thử lại" (the next render reads it again).
    While the retry runs, the card shows a skeleton in place of the message; the message and the
    button are only hidden from sight then (sr-only), so the focus stays on "Thử lại". How the
    retry went is said by the screen's shared toast (Dashboard::afterRetry): read again, the focus
    moves to the card's heading; still failing, the toast says so and stays until closed.
--}}
<div class="p-4">
    <div wire:loading.class="sr-only" wire:target="retry('{{ $card }}')" class="flex flex-col items-start gap-2.5 py-2">
        <p role="alert" class="flex items-start gap-1.5 text-small leading-normal text-danger-text">
            <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-[calc(1rem+var(--user-text-boost))] shrink-0" />
            <span>{{ __('dash.card_error') }}</span>
        </p>
        <button type="button" wire:click="retry('{{ $card }}')" wire:loading.attr="aria-disabled" wire:target="retry('{{ $card }}')" class="{{ $btn }}">
            <x-bs-admin::icon name="ri-refresh" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ __('dash.retry') }}</span>
        </button>
    </div>
    <div wire:loading.flex wire:target="retry('{{ $card }}')" aria-hidden="true" class="flex-col gap-2.5">
        <span class="h-[1.125rem] w-1/2 animate-pulse rounded bg-surface-hover"></span>
        <span class="h-3.5 w-[90%] animate-pulse rounded bg-surface-hover"></span>
        <span class="h-3.5 w-3/4 animate-pulse rounded bg-surface-hover"></span>
    </div>
</div>
