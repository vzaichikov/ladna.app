@extends('layouts.app')

@section('title', __('app.my_studio').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.my_studio') }}</h1>
            <p class="crm-page-copy">{{ __('app.my_studio_copy') }}</p>
        </div>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-slate-200" aria-label="{{ __('app.my_studio_tabs') }}">
        <a
            href="{{ route('dashboard.accounts.studio-settings.index', [$account, 'tab' => 'trainer-types']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'trainer-types' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.trainer_types') }}
        </a>
    </nav>

    @error('trainer_type')
        <div class="mt-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
            {{ $message }}
        </div>
    @enderror

    <section class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr] xl:items-start">
        <form method="POST" action="{{ route('dashboard.accounts.trainer-types.store', $account) }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-crm">
            @csrf

            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.create_trainer_type') }}</h2>

            <div class="mt-5 space-y-4">
                <label class="block">
                    <span class="crm-label">{{ __('app.name') }}</span>
                    <input name="name" value="{{ old('name') }}" required class="crm-field">
                    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.icon') }}</span>
                        <select name="icon" class="crm-field">
                            @foreach ($iconOptions as $icon => $labelKey)
                                <option value="{{ $icon }}" @selected(old('icon', 'user-round') === $icon)>{{ __($labelKey) }}</option>
                            @endforeach
                        </select>
                        @error('icon') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="crm-label">{{ __('app.color') }}</span>
                        <input name="color" type="color" value="{{ old('color', '#3B223F') }}" required class="crm-field h-12 p-1">
                        @error('color') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.sort_order') }}</span>
                        <input name="sort_order" type="number" min="0" max="32767" value="{{ old('sort_order', 10) }}" required class="crm-field">
                        @error('sort_order') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
                        <input type="hidden" name="is_default" value="0">
                        <input name="is_default" type="checkbox" value="1" @checked(old('is_default')) class="crm-checkbox">
                        {{ __('app.use_as_default') }}
                    </label>
                </div>
            </div>

            <div class="mt-5 flex justify-end">
                <x-ui.button type="submit">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
            </div>
        </form>

        <div class="space-y-4">
            @forelse ($trainerTypes as $trainerType)
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-crm">
                    <form method="POST" action="{{ route('dashboard.accounts.trainer-types.update', [$account, $trainerType]) }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <x-ui.trainer-type-badge :trainer-type="$trainerType" />

                            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="hidden" name="is_default" value="0">
                                <input name="is_default" type="checkbox" value="1" @checked(old('is_default', $trainerType->is_default)) class="crm-checkbox">
                                {{ __('app.default') }}
                            </label>
                        </div>

                        <label class="block">
                            <span class="crm-label">{{ __('app.name') }}</span>
                            <input name="name" value="{{ old('name', $trainerType->name) }}" required class="crm-field">
                        </label>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="block">
                                <span class="crm-label">{{ __('app.icon') }}</span>
                                <select name="icon" class="crm-field">
                                    @foreach ($iconOptions as $icon => $labelKey)
                                        <option value="{{ $icon }}" @selected(old('icon', $trainerType->icon) === $icon)>{{ __($labelKey) }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="crm-label">{{ __('app.color') }}</span>
                                <input name="color" type="color" value="{{ old('color', $trainerType->color) }}" required class="crm-field h-12 p-1">
                            </label>

                            <label class="block">
                                <span class="crm-label">{{ __('app.sort_order') }}</span>
                                <input name="sort_order" type="number" min="0" max="32767" value="{{ old('sort_order', $trainerType->sort_order) }}" required class="crm-field">
                            </label>
                        </div>

                        <div class="flex justify-end">
                            <x-ui.button type="submit" size="sm" variant="secondary">{{ __('app.save') }}</x-ui.button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('dashboard.accounts.trainer-types.destroy', [$account, $trainerType]) }}" data-confirm-delete class="mt-3 flex justify-end">
                        @csrf
                        @method('DELETE')

                        <x-ui.button type="submit" variant="danger" size="sm" :disabled="$trainerType->is_default || $trainerTypes->count() <= 1">
                            {{ __('app.delete') }}
                        </x-ui.button>
                    </form>
                </div>
            @empty
                <x-ui.empty-state :title="__('app.no_trainer_types')" icon="trainers" class="m-5" />
            @endforelse
        </div>
    </section>
@endsection
