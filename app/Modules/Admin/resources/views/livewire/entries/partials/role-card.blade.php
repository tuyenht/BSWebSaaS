{{-- The role card of a post list (App\Modules\Admin\Support\Entries\PostRoleNote): the card itself, or ($copy)
     the hidden one the guide's tour opens on its first step, with no "Đã hiểu" of its own. --}}
<section aria-label="{{ __('entries.role_aria') }}" @if ($copy) hidden data-role-copy @else data-role-note @endif data-guide="role" data-gov="{{ $note['governed'] ? '1' : '0' }}"
         @class([
             'flex flex-wrap items-center gap-x-2.5 gap-y-1.5 rounded-md border px-3.5 py-2 text-small text-text',
             'border-primary/25 bg-primary/6' => $note['governed'],
             'border-border bg-surface' => ! $note['governed'],
         ])>
    <x-bs-admin::icon name="ri-shield-user" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 text-primary-text" />
    <span class="inline-flex items-center whitespace-nowrap rounded-full bg-primary/12 px-2.5 py-0.5 text-caption font-bold text-primary-text">{{ $note['role'] }}</span>
    <span class="min-w-0 flex-[1_1_16rem] [overflow-wrap:anywhere]">{{ $note['text'] }}</span>
    <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ $note['summary'] }}</span>
    @unless ($copy)
        <x-bs-admin::hint-ok :key="\App\Modules\Admin\Support\FirstHints::POSTS_ROLE" focus="#entries-title" class="ms-auto" />
    @endunless
</section>
