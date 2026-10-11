<x-layout :header="false" title="Google Kalender · Amor">
    <div class="flex min-h-[80dvh] flex-col items-center justify-center gap-3 text-center">
        <img src="/icons/mascot.svg" alt="" width="96" height="98" class="mb-2">
        @if ($error)
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">Das hat nicht geklappt</h1>
            <p class="text-muted max-w-[36ch] text-pretty">{{ $error }}</p>
        @else
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">Google Kalender ist verbunden</h1>
            <p class="text-muted max-w-[36ch] text-pretty">Die Dienste kommen jetzt in den Kalender «{{ $calendar }}». In den Einstellungen von Amor kannst du einen anderen wählen. Hat sich diese Seite in einem eigenen Fenster geöffnet, schliess es und geh zurück zu Amor.</p>
            @if ($warning ?? null)
                <p class="max-w-[36ch] rounded-xl bg-peach-50 px-3.5 py-3 text-pretty text-peach-800 dark:bg-peach-400/10 dark:text-peach-300">Deine gespeicherten Dienste sind noch nicht im Kalender: {{ $warning }} Tippe in den Einstellungen auf «Einstellungen speichern», um es nochmals zu versuchen.</p>
            @endif
        @endif
        <x-button :href="route('settings').'#calendar'" class="mt-3">Zurück zu Amor</x-button>
    </div>
</x-layout>
