@props(['account', 'edition', 'axis', 'kindLocked' => false])

<article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div><h2 class="text-lg font-semibold">{{ $axis->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('app.festival_axis_kind_'.$axis->kind) }} · {{ $axis->options->count() }} {{ __('app.festival_options_count_suffix') }}</p></div>
        <x-festivals.settings-actions :active="$axis->is_active" :toggle-route="route('dashboard.accounts.festivals.axes.toggle', [$account, $edition, $axis])" :move-route="route('dashboard.accounts.festivals.axes.move', [$account, $edition, $axis])" />
    </div>

    <details class="mt-4 rounded-xl bg-stone-50 p-4">
        <summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('app.edit') }}</summary>
        <form method="POST" action="{{ route('dashboard.accounts.festivals.axes.update', [$account, $edition, $axis]) }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@csrf @method('PUT')
            <label><span class="crm-label">{{ __('app.code') }}</span><input name="code" value="{{ old('code', $axis->code) }}" required class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.name') }}</span><input name="name" value="{{ old('name', $axis->name) }}" required class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.festival_axis_kind') }}</span><select name="kind" class="crm-field" @disabled($kindLocked)>@foreach(['direction', 'style', 'age', 'level', 'entry_format', 'custom'] as $kind)<option value="{{ $kind }}" @selected($axis->kind === $kind)>{{ __('app.festival_axis_kind_'.$kind) }}</option>@endforeach</select>@if($kindLocked)<input type="hidden" name="kind" value="direction">@endif</label>
            <div class="flex flex-wrap items-end gap-4 pb-2"><input type="hidden" name="is_required" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_required" value="1" @checked($axis->is_required)>{{ __('app.required') }}</label><input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($axis->is_active)>{{ __('app.active') }}</label></div>
            <div class="sm:col-span-2 lg:col-span-4"><x-ui.button type="submit">{{ __('app.save') }}</x-ui.button></div>
        </form>
    </details>

    <div class="mt-5 space-y-3">
        @forelse($axis->options as $option)
            <div class="rounded-xl border border-stone-200 p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div><strong>{{ $option->label }}</strong><span class="ml-2 text-xs text-slate-500">{{ $option->code }} · {{ trans_choice('app.festival_category_usage_count', $option->categories_count, ['count' => $option->categories_count]) }}</span></div>
                    <x-festivals.settings-actions :active="$option->is_active" :toggle-route="route('dashboard.accounts.festivals.axis-options.toggle', [$account, $edition, $axis, $option])" :move-route="route('dashboard.accounts.festivals.axis-options.move', [$account, $edition, $axis, $option])" />
                </div>
                <details class="mt-3"><summary class="cursor-pointer text-xs font-semibold text-brand-700">{{ __('app.edit') }}</summary><form method="POST" action="{{ route('dashboard.accounts.festivals.axis-options.update', [$account, $edition, $axis, $option]) }}" class="mt-3 grid gap-3 sm:grid-cols-[1fr_2fr_auto]">@csrf @method('PUT')<input name="code" value="{{ $option->code }}" required class="crm-field" aria-label="{{ __('app.code') }}"><input name="label" value="{{ $option->label }}" required class="crm-field" aria-label="{{ __('app.name') }}"><input type="hidden" name="is_active" value="{{ $option->is_active ? 1 : 0 }}"><x-ui.button type="submit" variant="secondary">{{ __('app.save') }}</x-ui.button></form></details>
            </div>
        @empty
            <p class="rounded-xl bg-stone-50 p-4 text-sm text-slate-600">{{ __('app.festival_no_options') }}</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('dashboard.accounts.festivals.axis-options.store', [$account, $edition, $axis]) }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_2fr_auto]">@csrf
        <input name="code" required placeholder="{{ __('app.code') }}" class="crm-field"><input name="label" required placeholder="{{ __('app.festival_option_label') }}" class="crm-field"><x-ui.button type="submit">{{ __('app.add') }}</x-ui.button>
    </form>
</article>
