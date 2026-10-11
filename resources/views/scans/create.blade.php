<x-layout title="Add a roster · Amor">
    <div class="grid gap-6">
        <div class="grid gap-2">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-[1.625rem]">Add a roster</h1>
            <p class="text-muted max-w-[52ch] text-pretty">Take a photo of the printed duty roster. Amor finds your row, reads every day and shows you the result to check before anything goes into your calendar.</p>
        </div>

        @include('scan-form')

        <section class="grid gap-3 border-t border-sky-900/10 pt-6 dark:border-white/8" aria-labelledby="tips-heading">
            <h2 id="tips-heading" class="font-display text-[1.0625rem] font-semibold">For a photo Amor reads well</h2>
            <ul role="list" class="grid gap-2.5">
                @foreach ([
                    'Lay the roster flat and photograph it from straight above',
                    'Get the whole grid in the picture, with the dates at the top and the names on the left',
                    'Avoid glare and hard shadows; daylight works best',
                    'A fold or a slight angle is fine: Amor zooms in to read each cell',
                    'If the photo comes out sideways, tap Rotate before reading',
                ] as $tip)
                    <li class="flex gap-2.5">
                        <x-heroicon-m-check class="h-lh w-5 shrink-0 fill-leaf-600 dark:fill-leaf-300" />
                        <span class="text-pretty">{{ $tip }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="text-muted text-pretty">Reading takes a minute or two. Nothing is saved until you have checked the days and tapped Save shifts.</p>
        </section>
    </div>
</x-layout>
