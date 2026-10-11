{{-- Before the first roster, the intro has the button; afterwards it sits in the header (home only). --}}
<x-layout :add-button="(bool) $calendar">
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
                <form method="POST" action="{{ route('google.sync', $savedScan) }}" data-busy="Wird in Google Kalender eingetragen…">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" icon="arrow-path">Nochmals versuchen</x-button>
                </form>
            </section>
        @elseif ($savedScan && ! $google)
            <section class="grid gap-3 rounded-2xl bg-sky-100 p-4 dark:bg-sky-300/12" aria-labelledby="add-heading">
                <div class="grid gap-1">
                    <h2 id="add-heading" class="font-display text-lg font-semibold">Füge sie deinem Kalender hinzu</h2>
                    <p class="text-pretty">Öffne die Datei, tippe auf <strong class="font-semibold">Alle hinzufügen</strong> und wähle deinen Kalender.</p>
                </div>
                <div>
                    <x-button :href="route('scans.ics', $savedScan)" icon="calendar-days">Zum Kalender hinzufügen</x-button>
                </div>
            </section>
        @endif

        @if ($openScan)
            <div class="flex items-center gap-1 rounded-xl bg-sky-100 py-1.5 pr-1.5 pl-3.5 dark:bg-sky-300/12">
                <a href="{{ route('scans.show', $openScan) }}" class="flex flex-1 items-center gap-2 py-1.5 font-semibold text-sky-900 hover:underline dark:text-sky-50">
                    <span class="text-pretty">{{ $openScan->isPending() ? 'Ein Dienstplan wird gelesen.' : 'Ein Dienstplan wartet aufs Prüfen.' }}</span>
                    <x-heroicon-m-chevron-right class="size-5 shrink-0" />
                </a>
                <form method="POST" action="{{ route('scans.discard', $openScan) }}"
                    @unless ($openScan->isPending()) data-confirm="Dienstplan verwerfen? Die gelesenen Tage werden nicht gespeichert." @endunless>
                    @csrf
                    <button type="submit" class="relative rounded-lg px-2.5 py-2 text-[0.9375rem] font-semibold text-sky-900/70 hover:bg-sky-200 hover:text-sky-900 focus-visible:outline-2 focus-visible:outline-sky-500 dark:text-sky-100/70 dark:hover:bg-white/10 dark:hover:text-sky-50">
                        <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                        Verwerfen
                    </button>
                </form>
            </div>
        @endif

        @if ($calendar)
            <div class="flex items-center gap-4">
                <img src="/icons/mascot.svg" alt="" width="76" height="78" class="shrink-0 max-sm:w-16">
                <div class="grid gap-0.5">
                    <p class="font-display text-[1.0625rem] font-semibold text-balance sm:text-lg">Jeder Dienst direkt in deinem Kalender</p>
                    <p class="text-muted text-pretty">Hier ist dein Monat. Kommt ein neuer Dienstplan, füg ihn hinzu, und Amor hält alles aktuell.</p>
                </div>
            </div>

            @include('month-calendar', $calendar)

            <div class="grid gap-3">
                <p class="text-muted text-center text-pretty">
                    @if ($googleCalendar)
                        Gespeicherte Dienste landen auch in deinem Google Kalender «{{ $googleCalendar }}».
                    @else
                        Neuer Dienstplan? Fotografieren, Tage prüfen, fertig.
                    @endif
                </p>
            </div>
        @else
            <div class="grid justify-items-center gap-1 pt-4 text-center sm:gap-2">
                <img src="/icons/mascot.svg" alt="" width="140" height="143" class="mb-1 max-sm:w-[5.75rem]">
                <h1 class="max-w-[20ch] font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-[1.625rem]">Jeder Dienst direkt in deinem Kalender</h1>
                <p class="text-muted max-w-[38ch] text-pretty">Fotografiere den Dienstplan. Amor findet deine Zeile, liest jeden Tag und hält deinen Kalender aktuell, auch wenn sich der Plan ändert.</p>
            </div>
            <x-button :href="route('scans.create')" icon="camera">Ersten Dienstplan hinzufügen</x-button>
        @endif
    </div>
</x-layout>
