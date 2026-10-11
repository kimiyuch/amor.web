<x-layout :header="false" title="Google Calendar · Amor">
    <div class="flex min-h-[80dvh] flex-col items-center justify-center gap-3 text-center">
        <img src="/icons/mascot.svg" alt="" width="96" height="98" class="mb-2">
        @if ($error)
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">That didn't work</h1>
            <p class="text-muted max-w-[36ch] text-pretty">{{ $error }}</p>
        @else
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">Google Calendar is connected</h1>
            <p class="text-muted max-w-[36ch] text-pretty">Shifts now go into “{{ $calendar }}”. You can choose another calendar in Amor's settings. If this opened in a separate window, close it and go back to Amor.</p>
            @if ($warning ?? null)
                <p class="max-w-[36ch] rounded-xl bg-peach-50 px-3.5 py-3 text-pretty text-peach-800 dark:bg-peach-400/10 dark:text-peach-300">Your saved shifts aren't in the calendar yet: {{ $warning }} Tap Save settings in Amor's settings to try again.</p>
            @endif
        @endif
        <x-button :href="route('settings').'#calendar'" class="mt-3">Back to Amor</x-button>
    </div>
</x-layout>
