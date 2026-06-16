<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Quản trị' }}</title>
    @livewireStyles
</head>
<body>
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <a href="{{ route('admin.dashboard') }}" class="admin-brand" wire:navigate>Quản trị</a>

            <nav class="admin-nav">
                <a href="{{ route('admin.dashboard') }}" wire:navigate>Bảng điều khiển</a>
                @foreach ($navItems as $item)
                    <a href="{{ $item->url }}" wire:navigate>{{ $item->label }}</a>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('admin.logout') }}" class="admin-logout">
                @csrf
                <button type="submit">Đăng xuất</button>
            </form>
        </aside>

        <main class="admin-main">
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
