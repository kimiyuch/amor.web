@props(['title' => 'Amor', 'header' => true, 'addButton' => false])
<!DOCTYPE html>
<html lang="de" class="bg-white antialiased scheme-light-dark dark:bg-sky-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <meta name="description" content="Dienstplan fotografieren, und jeder Dienst landet in deinem Kalender.">
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
            <header class="pt-[env(safe-area-inset-top)]">
                {{-- Three columns, so the button sits in the middle whatever the sides measure. --}}
                <div class="mx-auto grid max-w-xl grid-cols-[1fr_auto_1fr] items-center gap-2 px-1 py-1">
                    <a href="{{ route('home') }}" aria-label="Startseite" class="justify-self-start pl-3 font-display text-xl font-semibold tracking-tight">Amor</a>
                    <div>
                        @if ($addButton)
                            <x-button :href="route('scans.create')" variant="secondary" size="sm" icon="camera" aria-label="Dienstplan hinzufügen" class="whitespace-nowrap">
                                {{-- The smallest phones get a shorter label, so it stays on one line. --}}
                                <span class="max-[359px]:hidden">Dienstplan hinzufügen</span>
                                <span class="min-[360px]:hidden">Plan hinzufügen</span>
                            </x-button>
                        @endif
                    </div>
                    <a href="{{ route('settings') }}" aria-label="Einstellungen" class="relative grid size-12 place-items-center justify-self-end rounded-full text-sky-900/60 hover:bg-sky-100 hover:text-sky-900 focus-visible:outline-2 focus-visible:outline-sky-500 dark:text-sky-100/60 dark:hover:bg-white/10 dark:hover:text-sky-50">
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
