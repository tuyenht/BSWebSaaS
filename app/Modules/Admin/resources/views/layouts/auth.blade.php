<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Quản trị' }}</title>
    @livewireStyles
</head>
<body>
    <main>{{ $slot }}</main>
    @livewireScripts
</body>
</html>
