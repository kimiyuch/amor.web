@php
    use Illuminate\Support\Carbon;

    $found = $result['person_found'] ?? false;
    $period = $found ? Carbon::parse($result['period_start'])->format('d.m.Y').' – '.Carbon::parse($result['period_end'])->format('d.m.Y') : '';
    $shiftCount = $days->whereIn('kind', ['timed', 'allday'])->count();
    $unsure = $days->where('uncertain', true)->count();
    $summary = $shiftCount.' '.Str::plural('shift', $shiftCount).($unsure ? ", {$unsure} to check" : '');
@endphp
<x-layout title="Check your shifts · Amor">
    @unless ($found)
        <div class="flex min-h-[70dvh] flex-col items-center justify-center gap-3 text-center">
            <img src="/icons/mascot.svg" alt="" width="96" height="98" class="mb-2">
            <h1 class="font-display text-[1.375rem] font-semibold tracking-tight text-balance sm:text-2xl">“{{ $scan->person }}” isn't in this roster</h1>
            @if ($result['notes'] ?? '')
                <p class="text-muted max-w-[36ch] text-pretty">{{ $result['notes'] }}</p>
            @endif
            <x-button :href="route('home')" class="mt-3">Try again</x-button>
        </div>
    @else
        <form method="POST" action="{{ route('scans.save', $scan) }}" id="review" class="grid gap-5">
            @csrf
            <div class="flex items-start justify-between gap-3">
                <div class="grid min-w-0 gap-1">
                    <h1 class="font-display text-[1.3125rem] font-semibold tracking-tight text-balance sm:text-2xl">{{ $result['row_label'] ?: $scan->person }}</h1>
                    <p class="text-muted grid tabular-nums">
                        <span>{{ $period }}</span>
                        <span data-summary>{{ $summary }}</span>
                    </p>
                </div>
                <x-button :href="route('home')" variant="secondary" size="sm" icon="arrow-path" class="mt-0.5 shrink-0">New scan</x-button>
            </div>

            @if ($result['notes'] ?? '')
                <p class="flex gap-2.5 rounded-xl bg-peach-50 px-3.5 py-3 text-peach-800 dark:bg-peach-400/10 dark:text-peach-300">
                    <x-heroicon-s-exclamation-triangle class="h-lh w-4 shrink-0" />
                    {{ $result['notes'] }}
                </p>
            @endif

            @if ($scan->strip_path)
                <figure class="grid gap-2">
                    <div class="overflow-x-auto overscroll-x-contain rounded-xl bg-sky-50 ring-1 ring-sky-900/10 dark:bg-white/5 dark:ring-white/10">
                        <img src="{{ route('scans.strip', $scan) }}" alt="Your row in the photo" class="block h-25 w-auto max-w-none sm:h-30">
                    </div>
                    <figcaption class="text-muted">Your row in the photo. Scroll sideways to compare.</figcaption>
                </figure>
            @endif

            <div class="-mx-4 -mt-2 overflow-x-auto">
                <table class="w-full border-collapse" data-days>
                    <thead class="sr-only"><tr><th>Date</th><th>Code</th><th>Event</th></tr></thead>
                    <tbody>
                        @php $month = null; @endphp
                        @foreach ($days as $day)
                            @php $date = Carbon::parse($day['date']); @endphp
                            @if ($date->format('Y-m') !== $month)
                                @php $month = $date->format('Y-m'); @endphp
                                <tr>
                                    <td colspan="3" class="px-4 pt-6 pb-2 font-display text-[1.0625rem] font-semibold">{{ $date->locale('en')->isoFormat('MMMM YYYY') }}</td>
                                </tr>
                            @endif
                            <tr class="group border-t border-sky-900/10 data-uncertain:bg-peach-50 dark:border-white/8 dark:data-uncertain:bg-peach-400/10"
                                data-day="{{ $day['date'] }}" data-kind="{{ $day['kind'] }}"
                                @if ($day['uncertain']) data-uncertain @endif
                                @if ($date->isToday()) data-today @endif>
                                <td class="w-px py-2 pr-1.5 pl-4 whitespace-nowrap tabular-nums sm:py-2.5">
                                    <span class="inline-block w-[2.2em] font-semibold group-data-today:text-sky-600 {{ $date->isWeekend() ? 'text-muted' : '' }} dark:group-data-today:text-sky-300">{{ $date->locale('en')->isoFormat('dd') }}</span>
                                    <time datetime="{{ $day['date'] }}" class="text-muted group-data-today:font-semibold group-data-today:text-sky-600 dark:group-data-today:text-sky-300">{{ $date->format('d.m.') }}</time>
                                </td>
                                <td class="w-21 px-1.5 py-2 sm:py-2.5">
                                    <input name="codes[{{ $day['date'] }}]" value="{{ $day['code'] }}" type="text" spellcheck="false" autocapitalize="characters"
                                        aria-label="Code for {{ $date->locale('en')->isoFormat('dd DD.MM.') }}"
                                        class="block min-h-10 w-full rounded-[10px] border border-sky-900/15 bg-sky-50 px-1 py-1.5 text-center font-bold uppercase focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-sky-500 group-data-[kind=allday]:border-transparent group-data-[kind=allday]:bg-sky-100 group-data-[kind=allday]:text-sky-700 group-data-[kind=free]:border-dashed group-data-[kind=free]:bg-transparent group-data-[kind=timed]:border-transparent group-data-[kind=timed]:bg-leaf-100 group-data-[kind=timed]:text-leaf-800 group-data-uncertain:border-[1.5px] group-data-uncertain:border-peach-400 dark:border-white/15 dark:bg-white/5 dark:group-data-[kind=allday]:bg-sky-300/13 dark:group-data-[kind=allday]:text-sky-300 dark:group-data-[kind=timed]:bg-leaf-300/14 dark:group-data-[kind=timed]:text-leaf-200"
                                        data-code-input>
                                </td>
                                <td class="py-2 pr-4 pl-2.5 leading-snug sm:py-2.5" data-event>
                                    <div class="font-semibold group-data-[kind=free]:font-normal group-data-[kind=free]:text-sky-900/70 group-data-[kind=skipped]:font-normal group-data-[kind=skipped]:text-sky-900/70 dark:group-data-[kind=free]:text-sky-100/65 dark:group-data-[kind=skipped]:text-sky-100/65" data-event-title>{{ match ($day['kind']) { 'free' => 'Free', 'skipped' => 'Not added', default => $day['event']->title } }}</div>
                                    <div class="text-muted tabular-nums" data-event-when>{{ $day['event']?->when() }}</div>
                                    <div class="flex items-center gap-1 text-peach-800 group-not-data-uncertain:hidden dark:text-peach-300">
                                        <x-heroicon-s-exclamation-triangle class="size-4 shrink-0" />
                                        Check on the photo
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>

        <script type="application/json" id="shift-codes">@json($codes)</script>
    @endunless

    @if ($found)
        <x-slot:bar>
            <div class="sticky bottom-0 z-10 bg-white/90 px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shadow-[0_-1px_0_rgb(20_38_58/0.08),0_-12px_32px_-16px_rgb(20_38_58/0.25)] backdrop-blur-lg dark:bg-sky-950/90 dark:shadow-[0_-1px_0_rgb(255_255_255/0.08)]">
                <div class="mx-auto flex max-w-xl gap-2.5">
                    @if ($scan->status === 'saved' && ! \App\Calendar\GoogleCalendar::connected())
                        <x-button :href="route('scans.ics', $scan)" variant="secondary" icon="calendar-days" class="shrink-0">Add to calendar</x-button>
                    @endif
                    <x-button type="submit" form="review" class="flex-1">
                        {{ $scan->status === 'saved' ? 'Save changes' : 'Save shifts' }}
                    </x-button>
                </div>
            </div>
        </x-slot:bar>
    @endif
</x-layout>
