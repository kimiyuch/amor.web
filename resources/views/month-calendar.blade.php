{{-- A month of shifts: a grid from Monday to Sunday, then the month's shifts with their times. --}}
@php
    $chip = [
        'timed' => 'bg-leaf-100 text-leaf-800 dark:bg-leaf-300/16 dark:text-leaf-200',
        'allday' => 'bg-sky-100 text-sky-700 dark:bg-sky-300/14 dark:text-sky-300',
    ];
    $iconLink = 'relative grid size-10 place-items-center rounded-full text-sky-900/70 hover:bg-sky-100 hover:text-sky-900 focus-visible:outline-2 focus-visible:outline-sky-500 dark:text-sky-100/70 dark:hover:bg-white/10 dark:hover:text-sky-50';
@endphp
<section class="grid gap-3" aria-labelledby="month-heading">
    <div class="flex items-center justify-between gap-2">
        <h1 id="month-heading" class="font-display text-[1.375rem] font-semibold tracking-tight sm:text-2xl">{{ $title }}</h1>
        <div class="flex items-center gap-1">
            @unless ($isCurrent)
                <x-button :href="route('home')" variant="secondary" size="sm" class="mr-1">Today</x-button>
            @endunless
            <a href="{{ route('home', ['month' => $previous]) }}" class="{{ $iconLink }}" aria-label="Previous month">
                <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                <x-heroicon-m-chevron-left class="size-5 shrink-0" />
            </a>
            <a href="{{ route('home', ['month' => $next]) }}" class="{{ $iconLink }}" aria-label="Next month">
                <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                <x-heroicon-m-chevron-right class="size-5 shrink-0" />
            </a>
        </div>
    </div>

    <table class="-mx-1 w-[calc(100%+0.5rem)] table-fixed border-collapse">
        <thead>
            <tr>
                @foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $i => $weekday)
                    <th scope="col" class="pb-1 text-center text-[0.8125rem] font-semibold whitespace-nowrap {{ $i >= 5 ? 'text-sky-900/45 dark:text-sky-100/40' : 'text-muted' }}">{{ $weekday }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($weeks as $week)
                <tr>
                    @foreach ($week as $day)
                        @php
                            $hasShift = in_array($day['kind'], ['timed', 'allday', 'skipped'], true);
                            $label = \Illuminate\Support\Carbon::parse($day['date'])->locale('en')->isoFormat('dddd D MMMM')
                                .($day['event'] ? ', '.$day['event']->title.', '.$day['event']->when() : ($day['kind'] === 'skipped' ? ', '.$day['code'] : ', free'));
                        @endphp
                        <td class="p-0.5 align-top">
                            @php
                                $link = $hasShift && $day['inMonth'];
                                $cell = 'flex h-[4.25rem] flex-col items-center gap-1 rounded-xl pt-1.5 '.($day['inMonth'] ? '' : 'opacity-40 ')
                                    .($link ? 'hover:bg-sky-50 focus-visible:outline-2 focus-visible:outline-sky-500 dark:hover:bg-white/5' : '');
                            @endphp
                            @if ($link)
                                <a href="#day-{{ $day['date'] }}" aria-label="{{ $label }}" class="{{ $cell }}">
                            @else
                                <div aria-label="{{ $label }}" class="{{ $cell }}">
                            @endif
                                <span class="grid size-7 place-items-center rounded-full text-[0.9375rem] tabular-nums {{ $day['today'] ? 'bg-sky-600 font-semibold text-white dark:bg-sky-300 dark:text-sky-950' : ($day['weekend'] ? 'text-muted' : '') }}">{{ $day['day'] }}</span>
                                @if (isset($chip[$day['kind']]) && str_contains($day['code'], '/'))
                                    {{-- Stacked codes ("S1/BD") as on the printed roster. --}}
                                    <span class="flex max-w-full flex-col items-center rounded-md px-1 py-px text-[0.8125rem]/[1.05] font-bold {{ $chip[$day['kind']] }}">
                                        @foreach (explode('/', $day['code'], 2) as $part)
                                            <span class="max-w-full truncate">{{ $part }}</span>
                                        @endforeach
                                    </span>
                                @elseif (isset($chip[$day['kind']]))
                                    <span class="max-w-full truncate rounded-md px-1 py-px text-sm/5 font-bold {{ $chip[$day['kind']] }}">{{ $day['code'] }}</span>
                                @elseif ($day['kind'] === 'skipped')
                                    <span class="text-muted max-w-full truncate text-sm/5">{{ $day['code'] }}</span>
                                @endif
                            {!! $link ? '</a>' : '</div>' !!}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($agenda->isNotEmpty())
        <ul role="list" class="mt-2 divide-y divide-sky-900/10 border-t border-sky-900/10 dark:divide-white/8 dark:border-white/8">
            @foreach ($agenda as $day)
                @php $date = \Illuminate\Support\Carbon::parse($day['date']); @endphp
                <li id="day-{{ $day['date'] }}" class="-mx-2 flex scroll-mt-20 items-center gap-3 rounded-lg px-2 py-2.5 target:bg-sky-50 dark:target:bg-white/5">
                    <span class="w-20 shrink-0 tabular-nums">
                        <span class="inline-block w-[1.9em] font-semibold {{ $day['today'] ? 'text-sky-600 dark:text-sky-300' : '' }}">{{ $day['weekday'] }}</span>
                        <span class="{{ $day['today'] ? 'font-semibold text-sky-600 dark:text-sky-300' : 'text-muted' }}">{{ $date->format('d.m.') }}</span>
                    </span>
                    <span class="min-w-0 flex-1">
                        @if ($day['event']?->named)
                            <span class="block font-semibold">{{ $day['event']->title }}</span>
                            <span class="text-muted block tabular-nums">{{ $day['event']->when() }}</span>
                        @elseif ($day['event'])
                            <span class="block font-semibold tabular-nums">{{ $day['event']->when() }}</span>
                        @else
                            <span class="text-muted block">Not in the calendar</span>
                        @endif
                    </span>
                    <span class="shrink-0 rounded-md px-1.5 py-px text-sm/5 font-bold {{ $chip[$day['kind']] ?? 'text-muted' }}">{{ $day['code'] }}</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-muted text-pretty">
            No shifts in {{ $month->locale('en')->isoFormat('MMMM') }}.
            @if ($nextShift)
                <a href="{{ route('home', ['month' => $nextShift->format('Y-m')]) }}#day-{{ $nextShift->toDateString() }}" class="font-semibold text-sky-700 underline decoration-sky-700/30 underline-offset-3 dark:text-sky-300 dark:decoration-sky-300/30">Next shift: {{ $nextShift->locale('en')->isoFormat('dd DD.MM.') }}</a>
            @endif
        </p>
    @endif

</section>
