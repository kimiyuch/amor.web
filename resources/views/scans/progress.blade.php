<x-layout title="Wird gelesen · Amor">
    <div class="flex min-h-[70dvh] flex-col items-center justify-center gap-3 text-center"
        @if ($scan->isPending()) data-scan-status="{{ route('scans.status', $scan) }}" @endif>
        @if ($scan->status === 'failed')
            <img src="/icons/mascot.svg" alt="" width="96" height="98" class="mb-2 opacity-80 grayscale-[.3]">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">Das hat nicht geklappt</h1>
            <p class="text-muted max-w-[36ch] text-pretty">{{ $scan->error }}</p>
            <x-button :href="route('home')" class="mt-3">Nochmals versuchen</x-button>
        @else
            <img src="/icons/mascot.svg" alt="" width="96" height="98" class="mb-2 animate-bob motion-reduce:animate-none">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">Dienstplan wird gelesen</h1>
            <p class="font-semibold" role="status" aria-live="polite" data-status-text>{{ $scan->status_text ?? 'Gleich geht es los…' }}</p>
            <p class="text-muted max-w-[34ch] text-pretty">Das dauert ein, zwei Minuten. Du kannst Amor in der Zwischenzeit schliessen, es liest weiter.</p>
            <form method="POST" action="{{ route('scans.discard', $scan) }}" class="mt-3">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Abbrechen</x-button>
            </form>
        @endif
    </div>
</x-layout>
