@extends('layouts.app')

@section('title', __('app.system_settings').' - '.__('app.app_name'))

@push('head')
    <link rel="stylesheet" href="{{ $previewFontsUrl }}">
@endpush

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.system_settings') }}</h1>
            <p class="crm-page-copy">{{ __('app.system_settings_copy') }}</p>
        </div>
    </div>

    <x-ui.panel class="mt-6">
        <form method="POST" action="{{ route('platform.settings.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="flex flex-wrap gap-2 border-b border-stone-100 pb-4">
                <a href="#appearance" class="rounded-lg bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-900 ring-1 ring-brand-100">{{ __('app.system_settings_tab_appearance') }}</a>
                <a href="#support" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 transition hover:bg-slate-50 hover:text-slate-950">{{ __('app.system_settings_tab_support') }}</a>
            </div>

            <div id="appearance">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.font_family') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.font_preview') }}</p>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($fontOptions as $fontKey => $font)
                        <label
                            class="cursor-pointer rounded-xl border p-4 transition {{ old('font_family', $currentFontKey) === $fontKey ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600' : 'border-stone-200 bg-white hover:border-brand-100 hover:bg-brand-50' }}"
                            style="font-family: '{{ $font['css_family'] }}', ui-sans-serif, system-ui, sans-serif;"
                        >
                            <input type="radio" name="font_family" value="{{ $fontKey }}" class="sr-only" @checked(old('font_family', $currentFontKey) === $fontKey)>
                            <span class="block text-xl font-semibold leading-none text-slate-950">{{ $font['label'] }}</span>
                            <span class="mt-3 block text-sm leading-6 text-slate-600">{{ __('app.app_tagline') }}</span>
                            <span class="mt-4 block text-xs font-semibold uppercase text-slate-400">{{ __('app.google_fonts') }}</span>
                        </label>
                    @endforeach
                </div>
                @error('font_family')
                    <span class="crm-help">{{ $message }}</span>
                @enderror
            </div>

            <div id="support" class="border-t border-stone-100 pt-6">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.system_support') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.system_support_copy') }}</p>
                <label class="mt-4 block">
                    <span class="crm-label">{{ __('app.support_url') }}</span>
                    <input name="support_url" type="url" value="{{ old('support_url', $supportUrl) }}" class="crm-field" placeholder="https://t.me/ladna_support">
                    @error('support_url')
                        <span class="crm-help">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="flex justify-end border-t border-stone-100 pt-5">
                <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
@endsection
