@props(['account', 'edition', 'category' => null, 'directions', 'workflows'])

@php
    $editing = $category?->exists;
    $deadline = $category?->registration_closes_at?->timezone($edition->timezone)->format('Y-m-d\TH:i');
@endphp

<form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.categories.update', [$account, $edition, $category]) : route('dashboard.accounts.festivals.categories.store', [$account, $edition]) }}" class="space-y-6">
    @csrf
    @if($editing) @method('PUT') @endif

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
        <div>
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_category_details') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_category_details_copy') }}</p>
        </div>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label>
                <span class="crm-label">{{ __('app.name') }}</span>
                <input name="name" value="{{ old('name', $category?->name) }}" maxlength="255" required class="crm-field">
                @error('name') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.festival_direction') }}</span>
                <select name="festival_direction_id" required class="crm-field">
                    <option value="">{{ __('app.select') }}</option>
                    @foreach($directions as $direction)
                        <option value="{{ $direction->id }}" @selected((int) old('festival_direction_id', $category?->festival_direction_id) === $direction->id)>
                            {{ $direction->name }}@unless($direction->is_active) · {{ __('app.inactive') }}@endunless
                        </option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_category_direction_help') }}</span>
                @error('festival_direction_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <div class="sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <label class="inline-flex items-center gap-3 rounded-xl border border-stone-200 px-4 py-3 text-sm font-medium text-slate-800">
                    <input type="checkbox" name="is_active" value="1" class="crm-checkbox" @checked(old('is_active', $category?->is_active ?? true))>
                    <span>{{ __('app.festival_category_active_help') }}</span>
                </label>
                @error('is_active') <span class="crm-help">{{ $message }}</span> @enderror
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
        <div>
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_category_eligibility') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_category_eligibility_copy') }}</p>
        </div>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label>
                <span class="crm-label">{{ __('app.festival_competition_format') }}</span>
                <select name="competition_format" required class="crm-field">
                    @foreach (\App\Enums\FestivalCompetitionFormat::cases() as $format)
                        <option value="{{ $format->value }}" @selected(old('competition_format', $category?->competition_format?->value ?? 'scored') === $format->value)>{{ __('app.festival_competition_format_'.$format->value) }}</option>
                    @endforeach
                </select>
                @error('competition_format') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.festival_minimum_entries_to_run') }}</span>
                <input type="number" min="1" max="10000" name="minimum_entries_to_run" value="{{ old('minimum_entries_to_run', $category?->minimum_entries_to_run ?? 1) }}" required class="crm-field">
                @error('minimum_entries_to_run') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.minimum_members') }}</span>
                <input type="number" min="1" max="100" name="min_members" value="{{ old('min_members', $category?->min_members ?? 1) }}" required class="crm-field">
                @error('min_members') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.maximum_members') }}</span>
                <input type="number" min="1" max="100" name="max_members" value="{{ old('max_members', $category?->max_members ?? 1) }}" required class="crm-field">
                @error('max_members') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.minimum_age') }}</span>
                <input type="number" min="0" max="100" name="min_age" value="{{ old('min_age', $category?->min_age) }}" class="crm-field">
                @error('min_age') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.maximum_age') }}</span>
                <input type="number" min="0" max="100" name="max_age" value="{{ old('max_age', $category?->max_age) }}" class="crm-field">
                @error('max_age') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
        <div>
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_category_performance') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_category_performance_copy') }}</p>
        </div>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label>
                <span class="crm-label">{{ __('app.minimum_duration_seconds') }}</span>
                <input type="number" min="1" name="min_duration_seconds" value="{{ old('min_duration_seconds', $category?->min_duration_seconds) }}" class="crm-field">
                @error('min_duration_seconds') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.maximum_duration_seconds') }}</span>
                <input type="number" min="1" name="max_duration_seconds" value="{{ old('max_duration_seconds', $category?->max_duration_seconds) }}" class="crm-field">
                @error('max_duration_seconds') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
        <div>
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_category_registration') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_category_registration_copy') }}</p>
        </div>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label>
                <span class="crm-label">{{ __('app.festival_registration_workflow') }}</span>
                <select name="festival_workflow_id" class="crm-field">
                    <option value="">{{ __('app.festival_standard_workflow') }}</option>
                    @foreach($workflows as $workflow)
                        <option value="{{ $workflow->id }}" @selected((int) old('festival_workflow_id', $category?->festival_workflow_id) === $workflow->id)>
                            {{ $workflow->name }}@unless($workflow->is_active) · {{ __('app.inactive') }}@endunless
                        </option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_category_workflow_help') }}</span>
                @error('festival_workflow_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label>
                <span class="crm-label">{{ __('app.festival_registration_closes_at') }}</span>
                <input type="datetime-local" name="registration_closes_at" value="{{ old('registration_closes_at', $deadline) }}" class="crm-field">
                <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_category_deadline_help', ['timezone' => $edition->timezone]) }}</span>
                @error('registration_closes_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
        <div>
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_category_requirements') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_category_requirements_help') }}</p>
        </div>
        <label class="mt-5 block">
            <span class="sr-only">{{ __('app.festival_category_requirements') }}</span>
            <textarea
                name="requirements_html"
                rows="10"
                maxlength="100000"
                class="crm-field"
                data-studio-rules-editor
                data-editor-height="300"
                data-placeholder="{{ __('app.festival_category_requirements_placeholder') }}"
            >{{ old('requirements_html', $category?->requirements_html) }}</textarea>
            @error('requirements_html') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    </section>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <x-ui.button :href="route('dashboard.accounts.festivals.settings.categories', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
        <x-ui.button type="submit" :disabled="$directions->isEmpty()">
            <x-ui.icon name="save" class="h-4 w-4" />
            {{ __('app.save') }}
        </x-ui.button>
    </div>
</form>
