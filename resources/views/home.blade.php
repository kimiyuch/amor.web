<x-layout>
    <div class="grid gap-5 sm:gap-6">
        @if (session('status'))
            <p class="flex gap-2.5 rounded-xl bg-leaf-100 px-3.5 py-3 text-leaf-800 dark:bg-leaf-300/14 dark:text-leaf-200" role="status">
                <x-heroicon-m-check-circle class="h-lh w-5 shrink-0" />
                {{ session('status') }}
            </p>
        @endif

        @if ($googleError && $savedScan)
            <section class="grid gap-3 rounded-2xl bg-peach-50 p-4 text-peach-800 dark:bg-peach-400/10 dark:text-peach-300" role="alert">
                <p class="flex gap-2.5"><x-heroicon-s-exclamation-triangle class="h-lh w-4 shrink-0" />{{ $googleError }}</p>
                <form method="POST" action="{{ route('google.sync', $savedScan) }}" data-busy="Adding to Google Calendar…">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" icon="arrow-path">Try again</x-button>
                </form>
            </section>
        @elseif ($savedScan && ! $google)
            <section class="grid gap-3 rounded-2xl bg-sky-100 p-4 dark:bg-sky-300/12" aria-labelledby="add-heading">
                <div class="grid gap-1">
                    <h2 id="add-heading" class="font-display text-lg font-semibold">Add them to your calendar</h2>
                    <p class="text-pretty">Open the file, tap <strong class="font-semibold">Add All</strong> and pick your calendar.</p>
                </div>
                <div>
                    <x-button :href="route('scans.ics', $savedScan)" icon="calendar-days">Add to calendar</x-button>
                </div>
            </section>
        @endif

        @if ($openScan)
            <a href="{{ route('scans.show', $openScan) }}" class="flex items-center justify-between gap-3 rounded-xl bg-sky-100 px-3.5 py-3 hover:bg-sky-200 dark:bg-sky-300/12 dark:hover:bg-sky-300/18">
                <span>{{ $openScan->isPending() ? 'A roster is being read.' : 'A roster is read and waiting for you to check it.' }}</span>
                <x-heroicon-m-chevron-right class="size-5 shrink-0" />
            </a>
        @endif

        @if ($calendar)
            <div class="flex items-center gap-4">
                <img src="/icons/mascot.svg" alt="" width="76" height="78" class="shrink-0 max-sm:w-16">
                <div class="grid gap-0.5">
                    <p class="font-display text-[1.0625rem] font-semibold text-balance sm:text-lg">Every shift, straight into your calendar</p>
                    <p class="text-muted text-pretty">Here's your month. When a new roster comes, add it, and Amor keeps everything up to date.</p>
                </div>
            </div>

            @include('month-calendar', $calendar)

            <div class="grid gap-3">
                <x-button :href="route('scans.create')" icon="camera">Add a roster</x-button>
                <p class="text-muted text-center text-pretty">
                    @if ($googleCalendar)
                        Saved shifts also go into your “{{ $googleCalendar }}” calendar on Google.
                    @else
                        Got a new roster? Photograph it, check the days, and they're in.
                    @endif
                </p>
            </div>
        @else
            <div class="grid justify-items-center gap-1 pt-4 text-center sm:gap-2">
                <img src="/icons/mascot.svg" alt="" width="140" height="143" class="mb-1 max-sm:w-[5.75rem]">
                <h1 class="max-w-[20ch] font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-[1.625rem]">Every shift, straight into your calendar</h1>
                <p class="text-muted max-w-[38ch] text-pretty">Photograph the duty roster. Amor finds your row, reads each day and keeps your calendar up to date, also when a roster changes.</p>
            </div>
            <x-button :href="route('scans.create')" icon="camera">Add your first roster</x-button>
        @endif
    </div>
</x-layout>
