<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-800">{{ __('admin.users') }}</h1>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-admin-success/30 bg-admin-success/10 px-4 py-2 text-sm text-admin-success">
            {{ session('status') }}
        </div>
    @endif

    {{-- Add an existing account to this site --}}
    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-card">
        <h2 class="mb-3 text-sm font-semibold text-gray-700">{{ __('admin.add_member') }}</h2>
        <form wire:submit="addMember" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="inviteEmail" class="mb-1 block text-xs font-medium text-gray-600">{{ __('admin.email') }}</label>
                <input type="email" id="inviteEmail" wire:model="inviteEmail" placeholder="name@example.com"
                       class="w-full rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 placeholder:text-gray-400 transition-colors focus:border-admin-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                @error('inviteEmail') <p class="mt-1 text-xs text-admin-danger">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1">
                <label for="inviteRole" class="mb-1 block text-xs font-medium text-gray-600">{{ __('admin.role') }}</label>
                <select id="inviteRole" wire:model="inviteRole"
                        class="w-full rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 transition-colors focus:border-admin-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                    <option value="">{{ __('admin.select_role') }}</option>
                    @foreach ($assignableRoles as $role)
                        <option value="{{ $role->name }}">{{ $role->name }}</option>
                    @endforeach
                </select>
                @error('inviteRole') <p class="mt-1 text-xs text-admin-danger">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                    class="rounded bg-admin-primary px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-admin-primary/90">
                {{ __('admin.add_member') }}
            </button>
        </form>
    </div>

    {{-- Members --}}
    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.name') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.email') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.role') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($members as $member)
                    <tr wire:key="member-{{ $member->id }}">
                        <td class="px-4 py-3 font-medium text-gray-800">{{ $member->name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $member->email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($member->roles as $role)
                                    <span class="inline-block rounded bg-admin-primary/10 px-2 py-0.5 text-xs font-medium text-admin-primary">{{ $role->name }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                @if ($assignableRoles->isNotEmpty())
                                    <select wire:change="changeRole({{ $member->id }}, $event.target.value)"
                                            class="rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700 focus:border-admin-primary focus:outline-none">
                                        <option value="">{{ __('admin.change_role') }}</option>
                                        @foreach ($assignableRoles as $role)
                                            <option value="{{ $role->name }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button type="button"
                                        wire:click="removeMember({{ $member->id }})"
                                        wire:confirm="{{ __('admin.member_remove_confirm') }}"
                                        class="rounded px-2 py-1 text-xs font-medium text-admin-danger transition-colors hover:bg-admin-danger/10">
                                    {{ __('admin.remove') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-400">{{ __('admin.no_members') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
