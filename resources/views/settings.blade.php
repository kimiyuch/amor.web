<x-layout title="Einstellungen · Amor">
    <form method="POST" action="{{ route('settings.update') }}" id="settings" class="grid"
        data-busy="{{ $googleConnected ? 'Wird gespeichert und in Google Kalender nachgeführt…' : 'Wird gespeichert…' }}">
        @csrf
        @method('PUT')

        <div class="grid gap-3 pb-6">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight sm:text-2xl">Einstellungen</h1>
            @if (session('status'))
                <p class="flex gap-2.5 rounded-xl bg-leaf-100 px-3.5 py-3 text-leaf-800 dark:bg-leaf-300/14 dark:text-leaf-200" role="status">
                    <x-heroicon-m-check-circle class="h-lh w-5 shrink-0" />
                    {{ session('status') }}
                </p>
            @endif
            @if ($errors->any())
                <p class="rounded-xl bg-peach-50 px-3.5 py-3 text-peach-800 dark:bg-peach-400/10 dark:text-peach-300">{{ $errors->first() }}</p>
            @endif
        </div>

        <section class="grid gap-3 border-t border-sky-900/10 py-6 dark:border-white/8">
            <h2 class="font-display text-[1.0625rem] font-semibold">Dienstplan</h2>
            <div class="grid gap-2">
                <label for="person" class="font-semibold">Name im Dienstplan</label>
                <input id="person" name="person" type="text" autocomplete="name" autocapitalize="words" value="{{ old('person', $person) }}" placeholder="z. B. Muster Anna" class="field-input">
            </div>
        </section>

        <section id="calendar" class="grid gap-3 border-t border-sky-900/10 py-6 dark:border-white/8">
            <h2 class="font-display text-[1.0625rem] font-semibold">Google Kalender</h2>
            @if ($googleConnected)
                <p class="flex gap-2 text-leaf-800 dark:text-leaf-200"><x-heroicon-m-check-circle class="h-lh w-5 shrink-0" />Verbunden. Gespeicherte Dienste kommen direkt in diesen Kalender, Korrekturen werden nachgeführt.</p>
                @if ($googleCalendars)
                    <div class="grid gap-2">
                        <label for="google_calendar" class="font-semibold">Kalender</label>
                        <div class="grid grid-cols-[1fr_--spacing(8)]">
                            <select id="google_calendar" name="google_calendar" class="field-input col-span-full row-start-1 appearance-none pr-8">
                                @foreach ($googleCalendars as $calendar)
                                    <option value="{{ $calendar['id'] }}" @selected($calendar['id'] === $googleCalendarId)>{{ $calendar['name'] }}</option>
                                @endforeach
                            </select>
                            <svg viewBox="0 0 8 5" width="8" height="5" fill="none" class="pointer-events-none col-start-2 row-start-1 place-self-center stroke-sky-900 dark:stroke-sky-50" aria-hidden="true"><path d="M.5.5 4 4 7.5.5" /></svg>
                        </div>
                    </div>
                @else
                    <p class="text-muted text-pretty">Amor konnte die Kalender gerade nicht laden und verwendet weiter den bisherigen.</p>
                @endif
                <div class="grid gap-2">
                    <label for="google_color" class="font-semibold">Farbe der Einträge</label>
                    <div class="grid grid-cols-[1fr_--spacing(8)]">
                        <select id="google_color" name="google_color" class="field-input col-span-full row-start-1 appearance-none pr-8">
                            <option value="">Farbe des Kalenders</option>
                            @foreach (\App\Calendar\GoogleCalendar::COLORS as $id => $name)
                                <option value="{{ $id }}" @selected((string) $id === $googleColor)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <svg viewBox="0 0 8 5" width="8" height="5" fill="none" class="pointer-events-none col-start-2 row-start-1 place-self-center stroke-sky-900 dark:stroke-sky-50" aria-hidden="true"><path d="M.5.5 4 4 7.5.5" /></svg>
                    </div>
                </div>
                <p><button type="submit" form="google-disconnect" class="font-semibold text-sky-700 underline decoration-sky-700/30 underline-offset-3 dark:text-sky-300 dark:decoration-sky-300/30">Google Kalender trennen</button></p>
            @elseif ($googleConfigured)
                <p class="text-pretty">Melde dich mit dem Google-Konto an, in dessen Kalender die Dienste kommen sollen. Das ist nur einmal nötig, auf einem beliebigen Gerät.</p>
                <div><x-button :href="route('google.connect')" variant="secondary" icon="calendar-days">Google Kalender verbinden</x-button></div>
            @else
                <p class="text-muted text-pretty">Damit Amor Dienste in Google Kalender eintragen kann, setze GOOGLE_CLIENT_ID und GOOGLE_CLIENT_SECRET in .env.</p>
            @endif
        </section>

        <section class="grid gap-3 border-t border-sky-900/10 py-6 dark:border-white/8">
            <h2 class="font-display text-[1.0625rem] font-semibold">Kalender-Abo</h2>
            <p class="text-pretty">Oder abonniere Amor als eigenen Kalender, auf iPhone, Mac oder bei Google. Er aktualisiert sich von selbst, wenn du einen Dienstplan speicherst.</p>
            @if ($subscribed)
                <p class="flex gap-2 text-leaf-800 dark:text-leaf-200"><x-heroicon-m-check-circle class="h-lh w-5 shrink-0" />Ein Kalender ist abonniert.</p>
            @endif
            <div class="flex flex-wrap gap-2">
                <x-button :href="$webcalUrl" variant="secondary" size="sm" icon="calendar-days">Auf iPhone oder Mac abonnieren</x-button>
                <x-button variant="secondary" size="sm" icon="clipboard-document" data-copy="{{ $feedUrl }}">Link kopieren</x-button>
            </div>
            <p class="text-muted text-pretty">Wer den Link hat, sieht deine Dienste. Ist er in falsche Hände geraten, <button type="submit" form="reset-feed" class="font-semibold text-sky-700 underline decoration-sky-700/30 underline-offset-3 dark:text-sky-300 dark:decoration-sky-300/30">erstelle einen neuen Link</button> und abonniere neu.</p>
        </section>

        <section class="grid gap-3 border-t border-sky-900/10 py-6 dark:border-white/8">
            <h2 class="font-display text-[1.0625rem] font-semibold">Dienstcodes</h2>
            <p class="text-muted text-pretty">Codes ohne Zeiten werden ganztägige Einträge. Liegt das Ende vor dem Beginn, endet der Dienst am Folgetag. Ohne Titel heisst der Eintrag wie der Code.</p>

            <div role="list" class="border-b border-sky-900/10 dark:border-white/8" data-codes>
                @foreach ($codes as $i => $code)
                    @include('settings-code-row', ['i' => $i, 'code' => $code])
                @endforeach
            </div>
            <template data-code-template>
                @include('settings-code-row', ['i' => '__INDEX__', 'code' => null])
            </template>
            <div>
                <x-button variant="secondary" size="sm" icon="plus" data-add-code>Code hinzufügen</x-button>
            </div>
        </section>
    </form>

    <form method="POST" action="{{ route('google.disconnect') }}" id="google-disconnect" data-confirm="Google Kalender trennen? Die Einträge, die schon drin sind, bleiben.">@csrf</form>
    <form method="POST" action="{{ route('settings.feed') }}" id="reset-feed" data-confirm="Kalender mit dem alten Link werden nicht mehr aktualisiert. Neuen Link erstellen?">@csrf</form>

    <section class="grid gap-3 border-t border-sky-900/10 py-6 dark:border-white/8">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="font-semibold text-sky-700 underline decoration-sky-700/30 underline-offset-3 dark:text-sky-300 dark:decoration-sky-300/30">Auf diesem Gerät abmelden</button>
        </form>
    </section>

    <x-slot:bar>
        <div class="sticky bottom-0 z-10 bg-white/90 px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shadow-[0_-1px_0_rgb(20_38_58/0.08),0_-12px_32px_-16px_rgb(20_38_58/0.25)] backdrop-blur-lg dark:bg-sky-950/90 dark:shadow-[0_-1px_0_rgb(255_255_255/0.08)]">
            <div class="mx-auto max-w-xl">
                <x-button type="submit" form="settings" class="w-full">Einstellungen speichern</x-button>
            </div>
        </div>
    </x-slot:bar>
</x-layout>
