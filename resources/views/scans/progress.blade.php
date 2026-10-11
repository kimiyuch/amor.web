<x-layout title="Reading · Amor">
    <div class="flex min-h-[70dvh] flex-col items-center justify-center gap-3 text-center"
        @if ($scan->isPending()) data-scan-status="{{ route('scans.status', $scan) }}" @endif>
        @if ($scan->status === 'failed')
            <img src="/icons/mascot.svg" alt="" width="96" height="98" class="mb-2 opacity-80 grayscale-[.3]">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">That didn't work</h1>
            <p class="text-muted max-w-[36ch] text-pretty">{{ $scan->error }}</p>
            <x-button :href="route('home')" class="mt-3">Try again</x-button>
        @else
            <img src="/icons/mascot.svg" alt="" width="96" height="98" class="mb-2 animate-bob motion-reduce:animate-none">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">Reading the roster</h1>
            <p class="font-semibold" role="status" aria-live="polite" data-status-text>{{ $scan->status_text ?? 'Waiting to start…' }}</p>
            <p class="text-muted max-w-[34ch] text-pretty">This takes a minute or two. You can close Amor meanwhile; it keeps reading.</p>
        @endif
    </div>
</x-layout>
