<!doctype html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Quản trị' }}</title>
    @livewireStyles
</head>
<body class="min-h-full bg-body font-sans text-base text-gray-700 antialiased">
<div x-data="{ open: false, collapsed: false }" class="min-h-screen">

    {{-- Mobile overlay --}}
    <div x-show="open" x-transition.opacity @click="open = false" x-cloak
         class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden"></div>

    {{-- Sidebar (Velzon dark) --}}
    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-[var(--spacing-sidebar)] flex-col bg-primary text-[#abb9e8] transition-all duration-200"
        :class="{
            'lg:w-[var(--spacing-sidebar-collapsed)]': collapsed,
            'lg:w-[var(--spacing-sidebar)]': !collapsed,
            'translate-x-0': open,
            '-translate-x-full lg:translate-x-0': !open
        }">
        {{-- Brand --}}
        <div class="flex h-[var(--spacing-header)] shrink-0 items-center px-5">
            <a href="{{ route('admin.dashboard') }}" wire:navigate
               class="flex items-center gap-2.5 font-semibold text-white">
                <span class="grid size-8 shrink-0 place-items-center rounded bg-white/10 font-bold">BS</span>
                <span x-show="!collapsed" class="text-lg tracking-tight">Quản trị</span>
            </a>
        </div>

        {{-- Nav (dashboard + dynamic, RBAC/tenant-scoped, content_sections) --}}
        <nav class="flex-1 overflow-y-auto px-3 py-4">
            <ul class="space-y-1">
                <li>
                    <a href="{{ route('admin.dashboard') }}" wire:navigate
                       @class([
                           'group flex items-center gap-3 rounded px-3 py-2.5 text-sm font-medium transition-colors hover:bg-white/10 hover:text-white',
                           'bg-white/15 text-white' => request()->routeIs('admin.dashboard'),
                       ])>
                        <x-admin::icon name="dashboard" class="size-5 shrink-0" />
                        <span x-show="!collapsed">Bảng điều khiển</span>
                    </a>
                </li>
                @foreach ($navItems as $item)
                    <li>
                        <a href="{{ $item->url }}" wire:navigate
                           class="group flex items-center gap-3 rounded px-3 py-2.5 text-sm font-medium transition-colors hover:bg-white/10 hover:text-white">
                            <x-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                            <span x-show="!collapsed">{{ $item->label }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </aside>

    {{-- Content column --}}
    <div class="flex min-h-screen flex-col transition-all duration-200"
         :class="collapsed ? 'lg:pl-[var(--spacing-sidebar-collapsed)]' : 'lg:pl-[var(--spacing-sidebar)]'">

        {{-- Topbar --}}
        <header class="sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-2 border-b border-gray-300 bg-white px-4 shadow-card">
            {{-- Open sidebar on mobile --}}
            <button type="button" @click="open = true" aria-label="Mở menu"
                    class="grid size-9 place-items-center rounded text-gray-600 transition-colors hover:bg-gray-100 lg:hidden">
                <x-admin::icon name="menu" class="size-5" />
            </button>
            {{-- Collapse sidebar on desktop --}}
            <button type="button" @click="collapsed = !collapsed" aria-label="Thu gọn menu"
                    class="hidden size-9 place-items-center rounded text-gray-600 transition-colors hover:bg-gray-100 lg:grid">
                <x-admin::icon name="menu" class="size-5" />
            </button>

            {{-- User menu --}}
            <div class="ms-auto" x-data="{ userOpen: false }">
                <div class="relative">
                    <button type="button" @click="userOpen = !userOpen"
                            class="flex items-center gap-2 rounded px-2 py-1.5 transition-colors hover:bg-gray-100">
                        <span class="grid size-8 place-items-center rounded-full bg-primary/10 font-semibold text-primary">
                            {{ \Illuminate\Support\Str::of(auth()->user()?->name ?? 'U')->substr(0, 1)->upper() }}
                        </span>
                        <span class="hidden text-sm font-medium text-gray-700 sm:block">{{ auth()->user()?->name ?? 'Tài khoản' }}</span>
                    </button>
                    <div x-show="userOpen" @click.outside="userOpen = false" x-transition x-cloak
                         class="absolute end-0 mt-1 w-48 rounded-md border border-gray-200 bg-white py-1 shadow-card">
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100">
                                <x-admin::icon name="logout" class="size-4" />
                                Đăng xuất
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>
