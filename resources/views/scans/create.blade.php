<x-layout title="Dienstplan hinzufügen · Amor">
    <div class="grid gap-6">
        <div class="grid gap-2">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-[1.625rem]">Dienstplan hinzufügen</h1>
            <p class="text-muted max-w-[52ch] text-pretty">Fotografiere den ausgedruckten Dienstplan. Amor findet deine Zeile, liest jeden Tag und zeigt dir das Ergebnis zum Prüfen, bevor etwas in deinen Kalender kommt.</p>
        </div>

        @include('scan-form')

        <section class="grid gap-3 border-t border-sky-900/10 pt-6 dark:border-white/8" aria-labelledby="tips-heading">
            <h2 id="tips-heading" class="font-display text-[1.0625rem] font-semibold">So liest Amor das Foto am besten</h2>
            <ul role="list" class="grid gap-2.5">
                @foreach ([
                    'Leg den Dienstplan flach hin und fotografiere ihn von oben',
                    'Das ganze Raster gehört aufs Bild, mit den Daten oben und den Namen links',
                    'Vermeide Spiegelungen und harte Schatten, Tageslicht ist am besten',
                    'Ein Knick oder ein leicht schräges Foto ist kein Problem: Amor zoomt in jede Zelle',
                    'Liegt das Foto quer, tippe vor dem Lesen auf Drehen',
                ] as $tip)
                    <li class="flex gap-2.5">
                        <x-heroicon-m-check class="h-lh w-5 shrink-0 fill-leaf-600 dark:fill-leaf-300" />
                        <span class="text-pretty">{{ $tip }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="text-muted text-pretty">Das Lesen dauert ein, zwei Minuten. Gespeichert wird erst, wenn du die Tage geprüft und auf «Dienste speichern» getippt hast.</p>
        </section>
    </div>
</x-layout>
