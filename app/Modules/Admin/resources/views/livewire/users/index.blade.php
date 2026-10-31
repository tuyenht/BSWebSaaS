<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-heading">{{ __('admin.users') }}</h1>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-admin-success/30 bg-admin-success/10 px-4 py-2 text-sm text-admin-success-text">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded border border-admin-danger/30 bg-admin-danger/10 px-4 py-2 text-sm text-admin-danger-text">
            {{ session('error') }}
        </div>
    @endif

    {{-- Add an existing account to this site --}}
    <div class="mb-6 rounded-lg border border-border bg-surface p-4 shadow-card">
        <h2 class="mb-3 text-sm font-semibold text-body-text">{{ __('admin.add_member') }}</h2>
        <form wire:submit="addMember" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="inviteEmail" class="mb-1 block text-xs font-medium text-body-muted">{{ __('admin.email') }}</label>
                <input type="email" id="inviteEmail" wire:model="inviteEmail" placeholder="name@example.com"
                       class="field w-full rounded border border-border bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text focus:bg-surface">
                @error('inviteEmail') <p class="mt-1 text-xs text-admin-danger-text">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1">
                <label for="inviteRole" class="mb-1 block text-xs font-medium text-body-muted">{{ __('admin.role') }}</label>
                <select id="inviteRole" wire:model="inviteRole"
                        class="field w-full rounded border border-border bg-surface-muted px-3 text-text transition-colors focus:border-primary-text focus:bg-surface">
                    <option value="">{{ __('admin.select_role') }}</option>
                    @foreach ($assignableRoles as $role)
                        <option value="{{ $role->name }}">{{ $role->name }}</option>
                    @endforeach
                </select>
                @error('inviteRole') <p class="mt-1 text-xs text-admin-danger-text">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                    class="rounded bg-admin-primary px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-admin-primary/90">
                {{ __('admin.add_member') }}
            </button>
        </form>
    </div>

    {{-- Members --}}
    <div class="overflow-x-auto rounded-lg border border-border bg-surface shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-surface-muted text-left text-xs uppercase tracking-wider text-body-muted">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.name') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.email') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.role') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-soft">
                @forelse ($members as $member)
                    <tr wire:key="member-{{ $member->ulid }}">
                        <td class="px-4 py-3 font-medium text-heading">{{ $member->name }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $member->email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($member->roles as $role)
                                    <span class="inline-block rounded bg-admin-primary/10 px-2 py-0.5 text-xs font-medium text-accent">{{ $role->name }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                @if ($assignableRoles->isNotEmpty())
                                    <select wire:change="changeRole(@js($member->ulid), $event.target.value)"
                                            class="field field-row rounded border border-border bg-surface px-2 text-text focus:border-primary-text">
                                        <option value="">{{ __('admin.change_role') }}</option>
                                        @foreach ($assignableRoles as $role)
                                            <option value="{{ $role->name }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button type="button"
                                        x-on:click="$bsConfirm({ text: @js(__('admin.member_remove_confirm')), confirm: @js(__('admin.remove')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.removeMember(@js($member->ulid)))"
                                        class="rounded px-2 py-1 text-xs font-medium text-admin-danger-text transition-colors hover:bg-admin-danger/10">
                                    {{ __('admin.remove') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-placeholder">{{ __('admin.no_members') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
