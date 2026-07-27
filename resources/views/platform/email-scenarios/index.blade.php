@extends('layouts.app')

@section('title', __('app.email_scenarios').' - '.__('app.platform'))

@section('content')
    @php
        $defaultScenarioTab = $groups->first()['group']->value;
        $requestedScenarioTab = old('scenario_tab', request('tab', $defaultScenarioTab));
        $activeScenarioTab = $groups->contains(
            fn (array $groupData): bool => $groupData['group']->value === $requestedScenarioTab,
        ) ? $requestedScenarioTab : $defaultScenarioTab;
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.email_scenarios') }}</h1>
            <p class="crm-page-copy">{{ __('app.email_scenarios_copy') }}</p>
        </div>
    </div>

    <div class="mt-6 flex items-start gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
        <span class="{{ $mailSettings->configured ? 'crm-status-active' : 'crm-status-muted' }}">
            {{ $mailSettings->configured ? __('app.configured') : __('app.email_delivery_local_fallback') }}
        </span>
        <div class="min-w-0">
            <div class="font-semibold text-slate-950">{{ __($mailSettings->engine->labelKey()) }}</div>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.email_integration_status_copy') }}</p>
        </div>
    </div>

    <form
        method="POST"
        action="{{ route('platform.email-scenarios.update') }}"
        class="mt-6 space-y-6"
        data-platform-settings-tabs
        data-active-tab="{{ $activeScenarioTab }}"
    >
        @csrf
        @method('PUT')
        <input type="hidden" name="scenario_tab" value="{{ $activeScenarioTab }}" data-platform-settings-active-tab>

        <div class="rounded-xl border border-stone-200 bg-white p-2 shadow-crm">
            <div class="overflow-x-auto">
                <div class="flex min-w-max gap-1 rounded-lg bg-stone-100 p-1" role="tablist" aria-label="{{ __('app.email_scenarios') }}">
                    @foreach ($groups as $groupData)
                        @php
                            $tabKey = $groupData['group']->value;
                            $tabId = 'email-scenarios-tab-'.$tabKey;
                            $panelId = 'email-scenarios-panel-'.$tabKey;
                        @endphp
                        <button
                            type="button"
                            id="{{ $tabId }}"
                            class="crm-tab whitespace-nowrap"
                            role="tab"
                            data-platform-settings-tab="{{ $tabKey }}"
                            aria-controls="{{ $panelId }}"
                            aria-selected="{{ $activeScenarioTab === $tabKey ? 'true' : 'false' }}"
                            tabindex="{{ $activeScenarioTab === $tabKey ? '0' : '-1' }}"
                        >
                            {{ __($groupData['group']->labelKey()) }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        @foreach ($groups as $groupData)
            @php
                $tabKey = $groupData['group']->value;
                $tabId = 'email-scenarios-tab-'.$tabKey;
                $panelId = 'email-scenarios-panel-'.$tabKey;
            @endphp
            <section
                id="{{ $panelId }}"
                data-platform-settings-panel="{{ $tabKey }}"
                role="tabpanel"
                aria-labelledby="{{ $tabId }}"
                @class(['hidden' => $activeScenarioTab !== $tabKey])
            >
                <h2 class="sr-only">{{ __($groupData['group']->labelKey()) }}</h2>
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($groupData['scenarios'] as $scenario)
                        @php
                            $enabled = (bool) old('scenarios.'.$scenario->value, $enabledMap[$scenario->value]);
                            $fieldId = 'scenario-'.$scenario->value;
                        @endphp
                        <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="font-bold text-slate-950">{{ __($scenario->labelKey()) }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __($scenario->descriptionKey()) }}</p>
                                </div>

                                <label for="{{ $fieldId }}" class="relative inline-flex shrink-0 cursor-pointer items-center">
                                    <input type="hidden" name="scenarios[{{ $scenario->value }}]" value="0">
                                    <input
                                        id="{{ $fieldId }}"
                                        type="checkbox"
                                        name="scenarios[{{ $scenario->value }}]"
                                        value="1"
                                        class="peer sr-only"
                                        @checked($enabled)
                                    >
                                    <span class="h-6 w-11 rounded-full bg-stone-300 transition peer-checked:bg-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-400 peer-focus-visible:ring-offset-2 after:absolute after:start-1 after:top-1 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:after:translate-x-5"></span>
                                    <span class="sr-only">{{ __('app.email_scenario_toggle', ['scenario' => __($scenario->labelKey())]) }}</span>
                                </label>
                            </div>

                            <dl class="mt-4 grid gap-3 border-t border-stone-100 pt-4 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="font-semibold text-slate-500">{{ __('app.audience') }}</dt>
                                    <dd class="mt-1 text-slate-800">{{ __($scenario->recipientKind()->labelKey()) }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-slate-500">{{ __('app.email_template') }}</dt>
                                    <dd class="mt-1 break-all font-mono text-xs text-slate-700">{{ $scenario->contentView() }}</dd>
                                </div>
                            </dl>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <x-ui.button
                                    :href="route('platform.email-scenarios.preview', [$scenario, 'en'])"
                                    variant="secondary"
                                    size="sm"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {{ __('app.preview_english') }}
                                </x-ui.button>
                                <x-ui.button
                                    :href="route('platform.email-scenarios.preview', [$scenario, 'uk'])"
                                    variant="secondary"
                                    size="sm"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {{ __('app.preview_ukrainian') }}
                                </x-ui.button>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="sticky bottom-4 flex justify-end">
            <x-ui.button type="submit">
                {{ __('app.save_email_scenarios') }}
            </x-ui.button>
        </div>
    </form>
@endsection
