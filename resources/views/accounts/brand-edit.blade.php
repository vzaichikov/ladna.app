@extends('layouts.app')

@section('title', __('app.my_brand').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.my_brand') }}</h1>
            <p class="crm-page-copy">{{ __('app.business_details_copy') }}</p>
        </div>
    </div>

    <nav class="mt-6 flex flex-wrap gap-2 border-b border-slate-200" aria-label="{{ __('app.my_brand') }}">
        @foreach ([
            'business' => __('app.business_details'),
            'formats' => __('app.studio_class_formats'),
            'opening_hours' => __('app.opening_hours'),
            'rules' => __('app.studio_rules_and_offer'),
            'pass_rules' => __('app.class_passes_and_classes'),
            'schedule_view' => __('app.public_schedule_view'),
            'api' => __('app.api'),
        ] as $tabKey => $tabLabel)
            <a
                href="{{ route('dashboard.accounts.general-settings.edit', $tabKey === 'business' ? $account : [$account, 'tab' => $tabKey]) }}"
                class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === $tabKey ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
            >
                {{ $tabLabel }}
            </a>
        @endforeach
    </nav>

    @if ($activeTab === 'formats')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'formats']) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.schedule-format-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'opening_hours')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'opening_hours']) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.opening-hours-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'rules')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'rules']) }}" class="mt-6 max-w-4xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.studio-rules-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'pass_rules')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'pass_rules']) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.class-pass-cancellation-rules-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'schedule_view')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'schedule_view']) }}" class="mt-6 max-w-4xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.schedule-view-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'api')
        @include('accounts.api-tokens', ['apiTokens' => $apiTokens])
    @else
        <form method="POST" action="{{ route('dashboard.accounts.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-6xl space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="brand_tab" value="business">

            @include('accounts.form-fields', ['splitContactPanel' => true])

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @endif
@endsection
