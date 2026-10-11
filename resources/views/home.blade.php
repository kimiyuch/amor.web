<x-layout>
    <div class="grid gap-5 sm:gap-6">
        @if (session('status'))
            <p class="flex gap-2.5 rounded-xl bg-leaf-100 px-3.5 py-3 text-leaf-800 dark:bg-leaf-300/14 dark:text-leaf-200" role="status">
                <x-heroicon-m-check-circle class="h-lh w-5 shrink-0" />
                {{ session('status') }}
            </p>
        @endif

        @if ($savedScan)
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

        <div class="grid justify-items-center gap-1 text-center sm:gap-2">
            <img src="/icons/mascot.svg" alt="" width="140" height="143" class="mb-1 max-sm:w-[5.75rem]">
            <h1 class="max-w-[20ch] font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-[1.625rem]">Every shift, straight into your calendar</h1>
            <p class="text-muted max-w-[36ch] text-pretty">Photograph the duty roster. Amor finds your row and reads each day.</p>
        </div>

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

        @if ($upcoming->isNotEmpty())
            <section class="mt-6 grid gap-2">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-display text-lg font-semibold">Coming up</h2>
                    <a href="{{ route('settings') }}#calendar" class="font-semibold text-sky-700 underline decoration-sky-700/30 underline-offset-3 dark:text-sky-300 dark:decoration-sky-300/30">Calendar</a>
                </div>
                <ul role="list" class="divide-y divide-sky-900/10 dark:divide-white/8">
                    @foreach ($upcoming as $event)
                        <li class="flex items-center gap-3 py-2.5">
                            <span class="w-22 shrink-0 tabular-nums">
                                <span class="font-semibold">{{ \Illuminate\Support\Carbon::parse($event->date)->locale('en')->isoFormat('dd') }}</span>
                                <span class="text-muted">{{ \Illuminate\Support\Carbon::parse($event->date)->format('d.m.') }}</span>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold">{{ $event->title }}</span>
                                <span class="text-muted block tabular-nums">{{ $event->when() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layout>
