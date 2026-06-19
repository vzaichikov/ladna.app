@props([
    'tagline' => false,
    'textClass' => 'text-slate-950',
    'taglineClass' => 'text-slate-500',
    'markClass' => 'h-9 w-9',
    'markWrapperClass' => null,
])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-3 align-middle']) }}>
    @if ($markWrapperClass)
        <span class="shrink-0 {{ $markWrapperClass }}">
            <img src="{{ asset('brand/ladna-mark.svg') }}" alt="" class="h-full w-full object-contain">
        </span>
    @else
        <img src="{{ asset('brand/ladna-mark.svg') }}" alt="" class="shrink-0 object-contain {{ $markClass }}">
    @endif
    <span class="flex flex-col justify-center leading-none">
        <span class="block text-[2rem] font-semibold leading-none {{ $textClass }}">{{ __('app.app_name') }}</span>
        @if ($tagline)
            <span class="mt-1 block text-xs font-medium leading-tight {{ $taglineClass }}">{{ __('app.app_tagline') }}</span>
        @endif
    </span>
</span>
