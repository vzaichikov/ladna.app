@props(['items'])

<nav {{ $attributes->class(['min-w-0 overflow-x-auto']) }} aria-label="{{ __('app.breadcrumbs') }}" data-app-breadcrumbs>
    <ol class="flex min-w-max items-center gap-1 py-1 text-sm font-semibold text-slate-500">
        @foreach ($items as $item)
            <li class="flex min-w-0 items-center gap-1">
                @if (! $loop->first)
                    <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-300" />
                @endif

                @if (! $loop->last && filled($item['href'] ?? null))
                    <a
                        href="{{ $item['href'] }}"
                        class="inline-flex min-h-10 max-w-48 items-center rounded-lg px-2 text-slate-600 transition hover:bg-brand-50 hover:text-brand-800 crm-focus sm:max-w-64"
                        title="{{ $item['label'] }}"
                    >
                        <span class="truncate">{{ $item['label'] }}</span>
                    </a>
                @else
                    <span
                        class="inline-flex min-h-10 max-w-56 items-center px-2 text-slate-950 sm:max-w-80"
                        title="{{ $item['label'] }}"
                        @if ($loop->last) aria-current="page" @endif
                    >
                        <span class="truncate">{{ $item['label'] }}</span>
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
