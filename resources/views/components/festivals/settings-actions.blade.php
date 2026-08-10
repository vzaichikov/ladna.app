@props(['toggleRoute', 'moveRoute', 'active'])

<div {{ $attributes->class(['flex flex-wrap items-center gap-2']) }}>
    <form method="POST" action="{{ $moveRoute }}">@csrf @method('PATCH')<input type="hidden" name="direction" value="up"><button class="rounded-lg border border-stone-300 px-2.5 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" title="{{ __('app.move_up') }}">↑</button></form>
    <form method="POST" action="{{ $moveRoute }}">@csrf @method('PATCH')<input type="hidden" name="direction" value="down"><button class="rounded-lg border border-stone-300 px-2.5 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" title="{{ __('app.move_down') }}">↓</button></form>
    <form method="POST" action="{{ $toggleRoute }}">@csrf @method('PATCH')<button class="rounded-lg border px-3 py-1.5 text-xs font-semibold {{ $active ? 'border-amber-300 text-amber-800 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-200' : 'border-emerald-300 text-emerald-800 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-200' }}">{{ $active ? __('app.deactivate') : __('app.activate') }}</button></form>
</div>
