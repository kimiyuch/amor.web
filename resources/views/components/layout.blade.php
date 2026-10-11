@props(['title' => 'Amor', 'header' => true])
<!DOCTYPE html>
<html lang="en" class="bg-white antialiased scheme-light-dark dark:bg-sky-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <meta name="description" content="Photograph the duty roster, and every shift goes into your calendar.">
    <meta name="theme-color" content="#e8f5fd" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0d1b2a" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Amor">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-linear-to-b from-sky-100/80 to-white to-[18rem] bg-no-repeat text-sky-900 dark:from-sky-950 dark:to-sky-950 dark:text-sky-50">
    <div class="isolate flex min-h-dvh flex-col">
        @if ($header)
            <header class="sticky top-0 z-10 bg-sky-50/85 pt-[env(safe-area-inset-top)] backdrop-blur-lg dark:bg-sky-950/85">
                <div class="mx-auto flex max-w-xl items-center justify-between py-1 pr-1 pl-4">
                    <a href="{{ route('home') }}" aria-label="Homepage" class="font-display text-xl font-semibold tracking-tight">Amor</a>
                    <a href="{{ route('settings') }}" aria-label="Settings" class="relative grid size-12 place-items-center rounded-full text-sky-900/60 hover:bg-sky-100 hover:text-sky-900 focus-visible:outline-2 focus-visible:outline-sky-500 dark:text-sky-100/60 dark:hover:bg-white/10 dark:hover:text-sky-50">
                        <x-heroicon-m-cog-6-tooth class="size-5 shrink-0" />
                    </a>
                </div>
            </header>
        @endif

        <main class="mx-auto w-full max-w-xl flex-1 px-4 pt-2 pb-10">
            {{ $slot }}
        </main>

        {{ $bar ?? '' }}
    </div>
</body>
</html>
