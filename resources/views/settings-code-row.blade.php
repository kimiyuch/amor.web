{{-- One row of the shift code table; also rendered into the template for new rows (index __INDEX__). --}}
<div role="listitem" class="grid grid-cols-[5rem_1fr_2.25rem] items-center gap-2 border-t border-sky-900/10 py-3 dark:border-white/8" data-code-row>
    <input name="codes[{{ $i }}][code]" value="{{ $code?->code }}" type="text" spellcheck="false" autocapitalize="characters" aria-label="Code" placeholder="Code" class="field-input py-2! text-center font-bold uppercase">
    <input name="codes[{{ $i }}][title]" value="{{ $code?->title }}" type="text" aria-label="Titel" placeholder="Titel" class="field-input py-2!">
    <button type="button" aria-label="Code entfernen" class="relative grid size-9 place-items-center rounded-full text-sky-900/60 hover:bg-sky-100 hover:text-sky-900 focus-visible:outline-2 focus-visible:outline-sky-500 dark:text-sky-100/60 dark:hover:bg-white/10" data-remove-code>
        <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
        <x-heroicon-s-x-mark class="size-4 shrink-0" />
    </button>
    <div class="col-span-full grid grid-cols-[1fr_1fr_auto] items-end gap-2">
        <label class="text-muted grid gap-0.5 text-[0.9375rem]">Beginn
            <input name="codes[{{ $i }}][start]" value="{{ $code?->start }}" type="time" class="field-input py-2! text-sky-900 dark:text-sky-50">
        </label>
        <label class="text-muted grid gap-0.5 text-[0.9375rem]">Ende
            <input name="codes[{{ $i }}][end]" value="{{ $code?->end }}" type="time" class="field-input py-2! text-sky-900 dark:text-sky-50">
        </label>
        <label class="flex min-h-11 items-center gap-2 px-1.5">
            <input type="hidden" name="codes[{{ $i }}][skip]" value="0">
            <span class="group inline-grid size-5 grid-cols-1">
                <input type="checkbox" name="codes[{{ $i }}][skip]" value="1" @checked($code?->skip)
                    class="col-start-1 row-start-1 appearance-none rounded-[6px] border border-sky-900/25 bg-white checked:border-peach-600 checked:bg-peach-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-peach-600 dark:border-white/15 dark:bg-white/5 dark:checked:border-peach-400 dark:checked:bg-peach-400 forced-colors:appearance-auto">
                <svg viewBox="0 0 14 14" fill="none" class="pointer-events-none col-start-1 row-start-1 size-7/8 self-center justify-self-center stroke-white dark:stroke-peach-900">
                    <path d="M3 8L6 11L11 3.5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="group-not-has-checked:opacity-0" />
                </svg>
            </span>
            Auslassen
        </label>
    </div>
</div>
