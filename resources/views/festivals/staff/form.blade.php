@extends('layouts.app')

@section('title', ($edition->exists ? $edition->title : __('app.festival_edition_create')).' - '.$account->name)

@section('content')
@php($timezone = old('timezone', $edition->timezone ?: ($account->timezone ?: config('app.timezone'))))
<div class="mx-auto max-w-5xl space-y-6">
    <header><a href="{{ route('dashboard.accounts.festivals.index', $account) }}" class="crm-page-kicker">← {{ __('app.festivals') }}</a><h1 class="crm-page-title mt-2">{{ $edition->exists ? $edition->title : __('app.festival_edition_create') }}</h1></header>
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" enctype="multipart/form-data" action="{{ $edition->exists ? route('dashboard.accounts.festivals.update', [$account, $edition]) : route('dashboard.accounts.festivals.store', $account) }}" class="space-y-6">@csrf @if($edition->exists) @method('PUT') @endif
        @unless($edition->exists)<input type="hidden" name="festival_purchase_id" value="{{ old('festival_purchase_id', $purchase->id) }}">@endunless
        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm"><h2 class="text-xl font-semibold">{{ __('app.festival_details') }}</h2><div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label><span class="crm-label">{{ __('app.festival_series') }}</span><select name="festival_series_id" required class="crm-field"><option value="">{{ __('app.select') }}</option>@foreach($series as $item)<option value="{{ $item->id }}" @selected((int) old('festival_series_id', $edition->festival_series_id) === $item->id)>{{ $item->name }}</option>@endforeach</select></label>
            <label><span class="crm-label">{{ __('app.title') }}</span><input name="title" value="{{ old('title', $edition->title) }}" required class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.summary') }}</span><input name="summary" value="{{ old('summary', $edition->summary) }}" maxlength="500" class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field">@foreach(\App\Enums\FestivalEditionStatus::cases() as $status)<option value="{{ $status->value }}" @selected(old('status', $edition->status?->value ?? 'draft') === $status->value)>{{ __('app.festival_status_'.$status->value) }}</option>@endforeach</select></label>
            <label><span class="crm-label">{{ __('app.festival_registration') }}</span><select name="registration_status" class="crm-field">@foreach(\App\Enums\FestivalRegistrationStatus::cases() as $status)<option value="{{ $status->value }}" @selected(old('registration_status', $edition->registration_status?->value ?? 'closed') === $status->value)>{{ __('app.festival_registration_'.$status->value) }}</option>@endforeach</select></label>
            <label><span class="crm-label">{{ __('app.starts_at') }}</span><input type="datetime-local" name="starts_at" value="{{ old('starts_at', $edition->starts_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.ends_at') }}</span><input type="datetime-local" name="ends_at" value="{{ old('ends_at', $edition->ends_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.festival_age_reference_date') }}</span><input type="date" name="age_reference_date" value="{{ old('age_reference_date', $edition->age_reference_date?->format('Y-m-d')) }}" required class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.timezone') }}</span><input name="timezone" value="{{ $timezone }}" required class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.currency') }}</span><input name="currency" value="{{ old('currency', $edition->currency ?: $account->default_currency) }}" maxlength="3" required class="crm-field uppercase"></label>
            <label><span class="crm-label">{{ __('app.festival_registration_opens') }}</span><input type="datetime-local" name="registration_opens_at" value="{{ old('registration_opens_at', $edition->registration_opens_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" class="crm-field"></label>
            <label><span class="crm-label">{{ __('app.festival_registration_closes') }}</span><input type="datetime-local" name="registration_closes_at" value="{{ old('registration_closes_at', $edition->registration_closes_at?->timezone($timezone)->format('Y-m-d\TH:i')) }}" class="crm-field"></label>
            <label class="sm:col-span-2">
                <span class="crm-label">{{ __('app.festival_hero_image') }}</span>
                @if ($edition->exists && $edition->coverMedia?->url())
                    <img src="{{ $edition->coverMedia->url() }}" alt="" class="mt-2 aspect-[16/7] w-full rounded-xl object-cover">
                @endif
                <input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp" class="crm-field">
                <span class="mt-2 block text-xs text-slate-500">{{ __('app.festival_hero_image_help') }}</span>
            </label>
        </div></section>
        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm"><h2 class="text-xl font-semibold">{{ __('app.festival_venue_content') }}</h2><div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label><span class="crm-label">{{ __('app.festival_venue') }}</span><input name="venue_name" value="{{ old('venue_name', $edition->venue_name) }}" class="crm-field"></label><label><span class="crm-label">{{ __('app.address') }}</span><input name="venue_address" value="{{ old('venue_address', $edition->venue_address) }}" class="crm-field"></label>
            <label class="sm:col-span-2"><span class="crm-label">{{ __('app.festival_map_url') }}</span><input type="url" name="venue_map_url" value="{{ old('venue_map_url', $edition->venue_map_url) }}" class="crm-field"></label>
            <label class="sm:col-span-2"><span class="crm-label">{{ __('app.description') }}</span><textarea name="description_html" rows="8" class="crm-field">{{ old('description_html', $edition->description_html) }}</textarea></label>
            <label class="sm:col-span-2"><span class="crm-label">{{ __('app.festival_rules') }}</span><textarea name="rules_html" rows="8" class="crm-field">{{ old('rules_html', $edition->rules_html) }}</textarea></label>
            <label class="sm:col-span-2"><span class="crm-label">{{ __('app.festival_directions') }}</span><textarea name="venue_directions" rows="3" class="crm-field">{{ old('venue_directions', $edition->venue_directions) }}</textarea></label>
        </div></section>
        <div class="flex justify-end"><x-ui.button type="submit" size="lg">{{ __('app.save') }}</x-ui.button></div>
    </form>
</div>
@endsection
