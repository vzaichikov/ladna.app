@extends('layouts.app')

@section('title', ($edition->exists ? $edition->title : __('app.festival_edition_create')).' - '.$account->name)

@section('content')
@php($timezone = old('timezone', $edition->timezone ?: ($account->timezone ?: config('app.timezone'))))
<div class="mx-auto max-w-5xl space-y-6">
    <x-ui.page-header :title="$edition->exists ? $edition->title : __('app.festival_edition_create')" :copy="__('app.festival_form_intro')" />

    @if ($edition->exists)
        <nav class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col" aria-label="{{ __('app.festival_edition_settings_tabs') }}">
            <a href="{{ route('dashboard.accounts.festivals.edit', [$account, $edition, 'tab' => 'details']) }}" class="crm-tab justify-start sm:justify-center" @if (($activeTab ?? 'details') === 'details') aria-current="page" @endif>
                {{ __('app.festival_details') }}
            </a>
            <a href="{{ route('dashboard.accounts.festivals.edit', [$account, $edition, 'tab' => 'branding']) }}" class="crm-tab justify-start sm:justify-center" @if (($activeTab ?? 'details') === 'branding') aria-current="page" @endif>
                {{ __('app.festival_branding') }}
            </a>
        </nav>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($edition->exists && ($activeTab ?? 'details') === 'branding')
        <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.accounts.festivals.branding.update', [$account, $edition]) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @unless ($selectedLandingTemplateIsAvailable)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                    <strong class="block">{{ __('app.festival_landing_template_unavailable_title') }}</strong>
                    {{ __('app.festival_landing_template_unavailable_copy', ['template' => $edition->landing_template]) }}
                </div>
            @endunless

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <div class="border-b border-stone-100 pb-5">
                    <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_landing_template') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_landing_template_help') }}</p>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    @php($oldLandingTemplate = old('landing_template'))
                    @foreach ($festivalLandingTemplates as $templateKey => $template)
                        @php($templateChecked = $oldLandingTemplate !== null
                            ? $oldLandingTemplate === $templateKey
                            : $selectedLandingTemplateIsAvailable && $edition->landing_template === $templateKey)
                        <x-festivals.landing-template-card
                            :template-key="$templateKey"
                            :template="$template"
                            name="landing_template"
                            :checked="$templateChecked"
                            :effective="$effectiveLandingTemplateKey === $templateKey"
                        />
                    @endforeach
                </div>
                @error('landing_template') <span class="crm-help">{{ $message }}</span> @enderror
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <div class="border-b border-stone-100 pb-5">
                    <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_landing_palette') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_landing_palette_help') }}</p>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($festivalLandingPalettes as $paletteKey => $palette)
                        @php($paletteChecked = old('landing_palette', $effectiveLandingPaletteKey) === $paletteKey)
                        <label @class([
                            'cursor-pointer rounded-xl border bg-white p-4 transition hover:border-violet-crm-500',
                            'border-violet-crm-500 ring-2 ring-violet-crm-100' => $paletteChecked,
                            'border-stone-200' => ! $paletteChecked,
                        ])>
                            <span class="flex items-center gap-3">
                                <input type="radio" name="landing_palette" value="{{ $paletteKey }}" class="crm-radio" @checked($paletteChecked)>
                                <span class="font-semibold text-slate-950">{{ __($palette['name_key']) }}</span>
                            </span>
                            <svg class="mt-4 h-10 w-full overflow-hidden rounded-lg border border-stone-200" viewBox="0 0 {{ count($palette['swatches']) }} 1" preserveAspectRatio="none" aria-hidden="true">
                                @foreach ($palette['swatches'] as $swatch)
                                    <rect x="{{ $loop->index }}" y="0" width="1" height="1" fill="{{ $swatch }}" />
                                @endforeach
                            </svg>
                        </label>
                    @endforeach
                </div>
                @error('landing_palette') <span class="crm-help">{{ $message }}</span> @enderror
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <div class="border-b border-stone-100 pb-5">
                    <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_hero_image') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_hero_image_help') }}</p>
                </div>
                @if ($edition->coverMedia?->url())
                    <img src="{{ $edition->coverMedia->url() }}" alt="" class="mt-5 aspect-video w-full rounded-xl border border-stone-200 object-cover">
                @endif
                <label class="mt-5 block">
                    <span class="crm-label">{{ __('app.festival_hero_image_replace') }}</span>
                    <input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp" class="crm-field">
                    @error('hero_image') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </section>

            <div class="flex justify-end rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
                <x-ui.button type="submit" size="lg">
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
            </div>
        </form>
    @else
    <form method="POST" action="{{ $edition->exists ? route('dashboard.accounts.festivals.update', [$account, $edition]) : route('dashboard.accounts.festivals.store', $account) }}" class="space-y-6">
        @csrf
        @if ($edition->exists)
            @method('PUT')
        @else
            <input type="hidden" name="festival_purchase_id" value="{{ old('festival_purchase_id', $purchase->id) }}">
        @endif

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_identity') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_identity_help') }}</p>
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_series') }}</span>
                    <select name="festival_series_id" required class="crm-field">
                        <option value="">{{ __('app.select') }}</option>
                        @foreach($series as $item)
                            <option value="{{ $item->id }}" @selected((int) old('festival_series_id', $edition->festival_series_id) === $item->id)>{{ $item->name }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_series_field_help') }}</span>
                    @error('festival_series_id') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.title') }}</span>
                    <input name="title" value="{{ old('title', $edition->title) }}" required class="crm-field" autocomplete="off">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_title_field_help') }}</span>
                    @error('title') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="crm-label">{{ __('app.summary') }}</span>
                    <textarea name="summary" rows="3" maxlength="500" class="crm-field">{{ old('summary', $edition->summary) }}</textarea>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_summary_field_help') }}</span>
                    @error('summary') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_schedule_lifecycle') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_schedule_lifecycle_help') }}</p>
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.status') }}</span>
                    <select name="status" class="crm-field">
                        @foreach(\App\Enums\FestivalEditionStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(old('status', $edition->status?->value ?? 'draft') === $status->value)>{{ __('app.festival_status_'.$status->value) }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_status_field_help') }}</span>
                    @error('status') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.timezone') }}</span>
                    <input name="timezone" value="{{ $timezone }}" required class="crm-field" autocomplete="off">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_timezone_field_help') }}</span>
                    @error('timezone') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.starts_at') }}</span>
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $edition->starts_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_starts_at_field_help') }}</span>
                    @error('starts_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.ends_at') }}</span>
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $edition->ends_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_ends_at_field_help') }}</span>
                    @error('ends_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_age_reference_date') }}</span>
                    <input type="date" name="age_reference_date" value="{{ old('age_reference_date', $edition->age_reference_date?->format('Y-m-d')) }}" required class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_age_reference_field_help') }}</span>
                    @error('age_reference_date') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.currency') }}</span>
                    <input name="currency" value="{{ old('currency', $edition->currency ?: $account->default_currency) }}" maxlength="3" required class="crm-field uppercase" autocomplete="off">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_currency_field_help') }}</span>
                    @error('currency') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_registration_settings') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_registration_settings_help') }}</p>
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_registration') }}</span>
                    <select name="registration_status" class="crm-field">
                        @foreach(\App\Enums\FestivalRegistrationStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(old('registration_status', $edition->registration_status?->value ?? 'closed') === $status->value)>{{ __('app.festival_registration_'.$status->value) }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_registration_status_field_help') }}</span>
                    @error('registration_status') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_max_entries_per_participant') }}</span>
                    <input type="number" name="max_entries_per_participant" min="1" value="{{ old('max_entries_per_participant', $edition->max_entries_per_participant) }}" class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_max_entries_help') }}</span>
                    @error('max_entries_per_participant') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_registration_opens') }}</span>
                    <input type="datetime-local" name="registration_opens_at" value="{{ old('registration_opens_at', $edition->registration_opens_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_registration_opens_field_help') }}</span>
                    @error('registration_opens_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_registration_closes') }}</span>
                    <input type="datetime-local" name="registration_closes_at" value="{{ old('registration_closes_at', $edition->registration_closes_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_registration_closes_field_help') }}</span>
                    @error('registration_closes_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_venue_access') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_venue_access_help') }}</p>
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_venue') }}</span>
                    <input name="venue_name" value="{{ old('venue_name', $edition->venue_name) }}" class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_venue_name_field_help') }}</span>
                    @error('venue_name') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.address') }}</span>
                    <input name="venue_address" value="{{ old('venue_address', $edition->venue_address) }}" class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_venue_address_field_help') }}</span>
                    @error('venue_address') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="crm-label">{{ __('app.festival_map_url') }}</span>
                    <input type="url" name="venue_map_url" value="{{ old('venue_map_url', $edition->venue_map_url) }}" class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_map_url_field_help') }}</span>
                    @error('venue_map_url') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="crm-label">{{ __('app.festival_directions') }}</span>
                    <textarea name="venue_directions" rows="3" class="crm-field">{{ old('venue_directions', $edition->venue_directions) }}</textarea>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_venue_directions_field_help') }}</span>
                    @error('venue_directions') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_public_content') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_public_content_help') }}</p>
            </div>
            <div class="mt-5 grid gap-5">
                <label class="block">
                    <span class="crm-label">{{ __('app.description') }}</span>
                    <textarea name="description_html" rows="8" class="crm-field" data-studio-rules-editor data-editor-height="300">{{ old('description_html', $edition->description_html) }}</textarea>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_description_field_help') }}</span>
                    @error('description_html') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_rules') }}</span>
                    <textarea name="rules_html" rows="8" class="crm-field" data-studio-rules-editor data-editor-height="300">{{ old('rules_html', $edition->rules_html) }}</textarea>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_rules_field_help') }}</span>
                    @error('rules_html') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <div class="flex justify-end rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
            <x-ui.button type="submit" size="lg">
                <x-ui.icon name="save" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </div>
    </form>
    @endif
</div>
@endsection
