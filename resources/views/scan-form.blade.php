{{-- Name and photo of a roster to read; on the home page. --}}
<form method="POST" action="{{ route('scans.store') }}" enctype="multipart/form-data" class="grid gap-5" data-scan-form>
    @csrf
    <div class="grid gap-2">
        <label for="person" class="font-semibold">Name in the roster</label>
        <input id="person" name="person" type="text" autocomplete="name" autocapitalize="words" required
            value="{{ old('person', $person) }}" placeholder="e.g. Muster Anna" class="field-input">
        @error('person')
            <p class="text-peach-700 dark:text-peach-300">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-2">
        <span id="photo-heading" class="font-semibold">Roster photo</span>
        <div class="relative">
        <label class="relative grid min-h-36 cursor-pointer overflow-hidden rounded-2xl bg-sky-50 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-sky-500 sm:min-h-48 dark:bg-white/5" aria-labelledby="photo-heading">
            <input name="photo" type="file" accept="image/*" required class="sr-only" data-photo-input>
            <span class="grid place-content-center justify-items-center gap-1 rounded-2xl border-[1.5px] border-dashed border-sky-500/50 p-4 text-center sm:p-6" data-photo-empty>
                <x-heroicon-m-camera class="mb-1 size-5 fill-sky-600 dark:fill-sky-300" />
                <strong class="font-semibold">Take or choose a photo</strong>
                <span class="text-muted max-w-[30ch] text-pretty">Hold the phone above the printed plan, with the whole grid in view.</span>
            </span>
            <img alt="Roster photo" class="max-h-[52vh] w-full object-contain" hidden data-photo-preview>
            <span class="absolute right-2.5 bottom-2.5 rounded-full bg-sky-950/70 px-3 py-1.5 text-[0.9375rem] font-semibold text-white backdrop-blur" hidden data-photo-change>Change photo</span>
        </label>
        {{-- Outside the label, so tapping it doesn't open the photo picker. --}}
        <button type="button" class="absolute bottom-2.5 left-2.5 flex items-center gap-1.5 rounded-full bg-sky-950/70 py-1.5 pr-3 pl-2 text-[0.9375rem] font-semibold text-white backdrop-blur focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500" hidden data-photo-rotate>
            <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
            <x-heroicon-m-arrow-uturn-right class="size-5 shrink-0" />
            Rotate
        </button>
        </div>
        @error('photo')
            <p class="text-peach-700 dark:text-peach-300">{{ $message }}</p>
        @enderror
    </div>

    <x-button type="submit" data-scan-submit>Read shifts</x-button>
</form>
