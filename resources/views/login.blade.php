<x-layout :header="false" title="Amor">
    <div class="flex min-h-[85dvh] flex-col items-center justify-center gap-6 text-center">
        <img src="/icons/mascot.svg" alt="" width="120" height="123" class="max-sm:w-24">
        <div class="grid gap-1">
            <h1 class="font-display text-3xl font-semibold tracking-tight">Amor</h1>
            <p class="text-muted text-pretty">Jeder Dienst direkt in deinem Kalender.</p>
        </div>

        <form method="POST" action="{{ url('/login') }}" class="grid w-full max-w-xs gap-3 text-left">
            @csrf
            <label for="password" class="font-semibold">Passwort</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required autofocus class="field-input">
            @error('password')
                <p class="text-peach-700 dark:text-peach-300">{{ $message }}</p>
            @enderror
            <x-button type="submit" class="mt-1">Amor öffnen</x-button>
        </form>
    </div>
</x-layout>
