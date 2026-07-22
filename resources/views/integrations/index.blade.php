@extends('layouts.app')

@section('title', $title.' - '.__('app.app_name'))

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ $heading }}</h1>
            <p class="crm-page-copy">{{ $copy }}</p>
        </div>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-slate-200" aria-label="{{ __('app.integration_categories') }}">
        @foreach ($categories as $categoryKey => $category)
            @php
                $isActive = $activeCategory->value === $categoryKey;
                $tabParameters = [...$tabRouteParameters, 'tab' => $categoryKey];
            @endphp
            <a
                href="{{ route($tabRoute, $tabParameters) }}"
                @if ($isActive) data-active-scroll-target @endif
                class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $isActive ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
            >
                {{ __($category['label_key']) }}
            </a>
        @endforeach
    </nav>

    @if ($centralSmsProviderUpdateRoute ?? null)
        @php
            $effectiveCentralSmsProvider = $effectiveCentralSmsSetting?->provider->value;
            $selectedCentralSmsProvider = old('central_sms_provider', $centralSmsProvider ?? $effectiveCentralSmsProvider);
        @endphp

        <form method="POST" action="{{ route($centralSmsProviderUpdateRoute) }}" class="mt-6 rounded-xl border border-violet-crm-200 bg-violet-crm-50 p-5 shadow-crm">
            @csrf
            @method('PUT')

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,24rem)_auto] lg:items-end">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.central_sms_provider') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.central_sms_provider_copy') }}</p>
                    @if (! $centralSmsProvider && $effectiveCentralSmsProvider)
                        <p class="mt-2 text-sm font-semibold text-amber-800">
                            {{ __('app.central_sms_provider_legacy_fallback', ['provider' => $providers[$effectiveCentralSmsProvider]['label']]) }}
                        </p>
                    @elseif ($centralSmsProvider && ! $effectiveCentralSmsProvider)
                        <p class="mt-2 text-sm font-semibold text-red-700">{{ __('app.central_sms_provider_unavailable') }}</p>
                    @endif
                </div>

                <label class="block" for="central-sms-provider">
                    <span class="crm-label">{{ __('app.central_sms_provider_label') }}</span>
                    <select id="central-sms-provider" name="central_sms_provider" class="crm-field" required>
                        @foreach ($providers as $providerKey => $provider)
                            <option value="{{ $providerKey }}" @selected($selectedCentralSmsProvider === $providerKey)>
                                {{ $provider['label'] }}
                            </option>
                        @endforeach
                    </select>
                    @error('central_sms_provider')
                        <span class="crm-help">{{ $message }}</span>
                    @enderror
                </label>

                <x-ui.button type="submit" class="w-full justify-center lg:w-auto">
                    {{ __('app.save') }}
                </x-ui.button>
            </div>
        </form>
    @endif

    <section class="mt-6 grid gap-5 xl:grid-cols-2">
        @foreach ($providers as $providerKey => $provider)
            @php
                $setting = $settings->get($providerKey);
                $hasUnreadableCredentials = $setting?->hasUnreadableCredentials() ?? false;
                $storedCredentials = $setting?->readableCredentials() ?? [];
                $credentials = \App\Support\IntegrationCatalog::displayCredentials($providerKey, $storedCredentials);
                $isEnabled = (bool) old('is_enabled', $setting?->is_enabled ?? false);
                $updateParameters = [...$updateRouteParameters, 'provider' => $providerKey];
            @endphp

            <form method="POST" action="{{ route($updateRoute, $updateParameters) }}" class="rounded-xl border border-slate-200 bg-white shadow-crm">
                @csrf
                @method('PUT')

                <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-950">{{ $provider['label'] }}</h2>
                            @if ($setting?->is_enabled)
                                <span class="crm-status-active">{{ __('app.enabled') }}</span>
                            @else
                                <span class="crm-status-muted">{{ __('app.disabled') }}</span>
                            @endif
                            @if (! $hasUnreadableCredentials && filled($storedCredentials))
                                <span class="crm-status-muted">{{ __('app.configured') }}</span>
                            @endif
                        </div>
                        @if (isset($provider['description_key']))
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __($provider['description_key']) }}</p>
                        @endif
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="hidden" name="is_enabled" value="0">
                        <input name="is_enabled" value="1" type="checkbox" class="crm-checkbox" @checked($isEnabled)>
                        {{ __('app.enabled') }}
                    </label>
                </div>

                @if ($hasUnreadableCredentials)
                    <div class="mx-5 mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                        {{ __('app.integration_credentials_unreadable') }}
                    </div>
                @endif

                @if (($provider['fields'] ?? []) !== [])
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        @foreach ($provider['fields'] as $fieldKey => $field)
                        @php
                            $fieldName = 'credentials['.$fieldKey.']';
                            $errorName = 'credentials.'.$fieldKey;
                            $fieldId = $providerKey.'-'.$fieldKey;
                            $isSensitive = (bool) ($field['sensitive'] ?? false);
                            $hasSecretValue = $isSensitive && filled($credentials[$fieldKey] ?? null);
                            $fieldValue = old('credentials.'.$fieldKey, $isSensitive ? '' : ($credentials[$fieldKey] ?? ''));
                            $fieldType = $field['type'] ?? 'text';
                        @endphp

                        <label class="block" for="{{ $fieldId }}">
                            <span class="crm-label">{{ __($field['label_key']) }}</span>

                            @if ($fieldType === 'select')
                                <select id="{{ $fieldId }}" name="{{ $fieldName }}" class="crm-field">
                                    @foreach (($field['options'] ?? []) as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}" @selected((string) $fieldValue === (string) $optionValue)>
                                            {{ str_starts_with($optionLabel, 'app.') ? __($optionLabel) : $optionLabel }}
                                        </option>
                                    @endforeach
                                </select>
                            @elseif ($fieldType === 'textarea')
                                <textarea
                                    id="{{ $fieldId }}"
                                    name="{{ $fieldName }}"
                                    rows="{{ $field['rows'] ?? 5 }}"
                                    @if ($isSensitive) autocomplete="new-password" placeholder="{{ $hasSecretValue ? __('app.leave_blank_to_keep_secret') : '' }}" @endif
                                    class="crm-field"
                                >{{ $isSensitive ? '' : $fieldValue }}</textarea>
                            @else
                                <input
                                    id="{{ $fieldId }}"
                                    name="{{ $fieldName }}"
                                    type="{{ $fieldType === 'password' ? 'password' : ($fieldType === 'integer' ? 'number' : ($fieldType === 'email' ? 'email' : 'text')) }}"
                                    value="{{ $isSensitive ? '' : $fieldValue }}"
                                    @if ($fieldType === 'integer' && isset($field['min'])) min="{{ $field['min'] }}" @endif
                                    @if ($fieldType === 'integer' && isset($field['max'])) max="{{ $field['max'] }}" @endif
                                    @if ($isSensitive) autocomplete="new-password" placeholder="{{ $hasSecretValue ? __('app.leave_blank_to_keep_secret') : '' }}" @endif
                                    class="crm-field"
                                >
                            @endif

                            @if ($hasSecretValue)
                                <span class="mt-1 block text-xs font-semibold text-emerald-700">{{ __('app.secret_configured') }}</span>
                            @endif
                            @error($errorName)
                                <span class="crm-help">{{ $message }}</span>
                            @enderror
                        </label>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-end border-t border-slate-100 px-5 py-4">
                    <x-ui.button type="submit">
                        {{ __('app.save') }}
                    </x-ui.button>
                </div>
            </form>
        @endforeach
    </section>
@endsection
