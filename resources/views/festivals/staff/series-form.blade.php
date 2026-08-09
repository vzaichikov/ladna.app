@extends('layouts.app')

@section('title', ($series->exists ? __('app.festival_series_edit') : __('app.festival_series_create')).' - '.$account->name)

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <header>
        <a href="{{ route('dashboard.accounts.festivals.index', ['account' => $account, 'tab' => 'series']) }}" class="crm-page-kicker">← {{ __('app.festival_series_tab') }}</a>
        <h1 class="crm-page-title mt-2">{{ $series->exists ? __('app.festival_series_edit') : __('app.festival_series_create') }}</h1>
    </header>

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $series->exists ? route('dashboard.accounts.festivals.series.update', [$account, $series]) : route('dashboard.accounts.festivals.series.store', $account) }}" class="space-y-6">
        @csrf
        @if ($series->exists)
            @method('PUT')
        @endif

        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="sm:col-span-2">
                    <span class="crm-label">{{ __('app.name') }}</span>
                    <input name="name" value="{{ old('name', $series->name) }}" required maxlength="255" class="crm-field">
                </label>
                <label class="sm:col-span-2">
                    <span class="crm-label">{{ __('app.summary') }}</span>
                    <textarea name="summary" rows="3" maxlength="500" class="crm-field">{{ old('summary', $series->summary) }}</textarea>
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_organizer') }}</span>
                    <input name="organizer_name" value="{{ old('organizer_name', $series->organizer_name) }}" maxlength="255" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.email') }}</span>
                    <input type="email" name="organizer_email" value="{{ old('organizer_email', $series->organizer_email) }}" maxlength="255" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.phone') }}</span>
                    <input name="organizer_phone" value="{{ old('organizer_phone', $series->organizer_phone) }}" maxlength="50" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.brand_color') }}</span>
                    <input name="brand_color" value="{{ old('brand_color', $series->brand_color) }}" placeholder="#10233F" pattern="#[0-9a-fA-F]{6}" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_telegram_url') }}</span>
                    <input type="url" name="organizer_telegram_url" value="{{ old('organizer_telegram_url', $series->organizer_telegram_url) }}" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_instagram_url') }}</span>
                    <input type="url" name="organizer_instagram_url" value="{{ old('organizer_instagram_url', $series->organizer_instagram_url) }}" class="crm-field">
                </label>
                <label class="flex items-center gap-3 sm:col-span-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" class="crm-checkbox" @checked(old('is_active', $series->exists ? $series->is_active : true))>
                    <span class="text-sm font-semibold text-slate-700">{{ __('app.active') }}</span>
                </label>
            </div>
        </section>

        <div class="flex justify-end">
            <x-ui.button type="submit" size="lg">{{ __('app.save') }}</x-ui.button>
        </div>
    </form>
</div>
@endsection
