@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'icon' => null])
@php
    $classes = [
        'relative inline-flex items-center justify-center gap-2 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500 disabled:opacity-40 active:not-disabled:scale-98 dark:focus-visible:outline-sky-300',
        $size === 'sm'
            ? 'min-h-9 rounded-[10px] py-1.5 text-[0.9375rem] '.($icon ? 'pr-3 pl-2' : 'px-3')
            : 'min-h-12 rounded-xl py-3 '.($icon ? 'pr-4 pl-3' : 'px-4'),
        $variant === 'primary'
            ? 'bg-peach-600 text-white hover:bg-peach-700 dark:bg-peach-400 dark:text-peach-900 dark:hover:bg-peach-300'
            : 'bg-sky-100 text-sky-900 hover:bg-sky-200 dark:bg-sky-300/12 dark:text-sky-50 dark:hover:bg-sky-300/18',
    ];
    $tag = $href ? 'a' : 'button';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @else type="{{ $attributes->get('type', 'button') }}" @endif {{ $attributes->except('type')->class($classes) }}>
    @if ($size === 'sm')
        <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
    @endif
    @if ($icon)
        <x-dynamic-component :component="'heroicon-m-'.$icon" class="size-5 shrink-0" />
    @endif
    {{ $slot }}
</{{ $tag }}>
