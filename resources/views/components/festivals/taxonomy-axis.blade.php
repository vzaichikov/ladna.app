@props(['account', 'edition', 'axis', 'kindLocked' => false])

@php($axisEditId = 'festival-axis-edit-'.$axis->id)

<article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ $axis->name }}</h2>
            <p class="mt-1 text-sm text-slate-500">
                @unless ($kindLocked)
                    {{ __('app.festival_axis_kind_'.$axis->kind) }} ·
                @endunless
                {{ $axis->options->count() }} {{ __('app.festival_options_count_suffix') }}
            </p>
        </div>
        <x-festivals.settings-actions
            :active="$axis->is_active"
            :toggle-route="route('dashboard.accounts.festivals.axes.toggle', [$account, $edition, $axis])"
            :move-route="route('dashboard.accounts.festivals.axes.move', [$account, $edition, $axis])"
            :edit-target="$axisEditId"
            class="lg:justify-end"
        />
    </div>

    <form
        id="{{ $axisEditId }}"
        method="POST"
        action="{{ route('dashboard.accounts.festivals.axes.update', [$account, $edition, $axis]) }}"
        class="mt-5 hidden gap-4 rounded-xl border border-stone-200 bg-stone-50 p-4 sm:grid-cols-2 lg:grid-cols-4"
    >
        @csrf
        @method('PUT')
        <label class="sm:col-span-2">
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="name" value="{{ old('name', $axis->name) }}" required class="crm-field">
            @if ($kindLocked)
                <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_direction_group_name_help') }}</span>
            @endif
        </label>
        @if ($kindLocked)
            <input type="hidden" name="kind" value="direction">
        @else
            <label>
                <span class="crm-label">{{ __('app.festival_axis_kind') }}</span>
                <select name="kind" class="crm-field">
                    @foreach(['direction', 'style', 'age', 'level', 'entry_format', 'custom'] as $kind)
                        <option value="{{ $kind }}" @selected($axis->kind === $kind)>{{ __('app.festival_axis_kind_'.$kind) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="flex flex-wrap items-end gap-4 pb-2">
            <input type="hidden" name="is_required" value="0">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_required" value="1" class="crm-checkbox" @checked($axis->is_required)>
                {{ __('app.required') }}
            </label>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" class="crm-checkbox" @checked($axis->is_active)>
                {{ __('app.active') }}
            </label>
        </div>
        <div class="sm:col-span-2 lg:col-span-4">
            <x-ui.button type="submit">
                <x-ui.icon name="save" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </div>
    </form>

    <div class="mt-5 overflow-hidden rounded-xl border border-stone-200">
        @forelse($axis->options as $option)
            @php($optionEditId = 'festival-option-edit-'.$option->id)
            <div class="border-b border-stone-100 p-4 last:border-b-0">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <strong class="text-slate-950">{{ $option->label }}</strong>
                        <span class="mt-1 block text-xs text-slate-500 sm:mt-0 sm:ml-2 sm:inline">
                            {{ trans_choice('app.festival_category_usage_count', $option->categories_count, ['count' => $option->categories_count]) }}
                        </span>
                    </div>
                    <x-festivals.settings-actions
                        :active="$option->is_active"
                        :toggle-route="route('dashboard.accounts.festivals.axis-options.toggle', [$account, $edition, $axis, $option])"
                        :move-route="route('dashboard.accounts.festivals.axis-options.move', [$account, $edition, $axis, $option])"
                        :edit-target="$optionEditId"
                        class="lg:justify-end"
                    />
                </div>
                <form
                    id="{{ $optionEditId }}"
                    method="POST"
                    action="{{ route('dashboard.accounts.festivals.axis-options.update', [$account, $edition, $axis, $option]) }}"
                    class="mt-4 hidden gap-3 rounded-xl bg-stone-50 p-4 sm:grid-cols-[minmax(0,1fr)_auto]"
                >
                    @csrf
                    @method('PUT')
                    <label>
                        <span class="crm-label">{{ $kindLocked ? __('app.festival_direction_name') : __('app.name') }}</span>
                        <input name="label" value="{{ $option->label }}" required class="crm-field">
                    </label>
                    <input type="hidden" name="is_active" value="{{ $option->is_active ? 1 : 0 }}">
                    <x-ui.button type="submit" class="self-end">
                        <x-ui.icon name="save" class="h-4 w-4" />
                        {{ __('app.save') }}
                    </x-ui.button>
                </form>
            </div>
        @empty
            <p class="p-4 text-sm text-slate-600">{{ __('app.festival_no_options') }}</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('dashboard.accounts.festivals.axis-options.store', [$account, $edition, $axis]) }}" class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
        @csrf
        <label>
            <span class="crm-label">{{ $kindLocked ? __('app.festival_direction_name') : __('app.festival_option_label') }}</span>
            <input name="label" required class="crm-field" placeholder="{{ $kindLocked ? __('app.festival_direction_name_placeholder') : __('app.festival_option_label') }}">
        </label>
        <x-ui.button type="submit" class="self-end">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.add') }}
        </x-ui.button>
    </form>
</article>
