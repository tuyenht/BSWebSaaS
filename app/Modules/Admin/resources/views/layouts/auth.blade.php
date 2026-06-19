<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Quản trị' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="admin-shell min-h-screen bg-body font-sans text-base text-gray-700 antialiased">
    <main class="grid min-h-screen place-items-center p-4">{{ $slot }}</main>
    @livewireScripts
</body>
</html>
