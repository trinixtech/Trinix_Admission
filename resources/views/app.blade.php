<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#0f172a">
    <meta name="app-version" content="{{ config('app.version') }}">
    <title>{{ config('app.name') }}</title>
    <script>
        // Apply the saved theme before the page paints (no flash of the wrong theme).
        try { var t = localStorage.getItem('theme'); if (t === 'dark' || (t !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/main.ts'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
