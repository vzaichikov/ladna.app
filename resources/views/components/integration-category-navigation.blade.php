@props([
    'account',
    'activeCategory' => null,
    'activeTab' => null,
    'canManageApiKeys' => true,
    'showProviderCategories' => true,
])

@php
    $categories = [
        \App\Enums\IntegrationCategory::Payment,
        \App\Enums\IntegrationCategory::Fiscalization,
        \App\Enums\IntegrationCategory::Messaging,
    ];
@endphp

<nav {{ $attributes->class(['flex gap-2 overflow-x-auto pb-1']) }} aria-label="{{ __('app.integration_categories') }}">
    <a
        href="{{ route('dashboard.accounts.integrations.index', $account) }}"
        class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition {{ $activeTab === 'ai' ? 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' }}"
        @if ($activeTab === 'ai') aria-current="page" @endif
    >
        {{ __('app.connections_tab_ai') }}
    </a>

    @if ($canManageApiKeys)
        <a
            href="{{ route('dashboard.accounts.integrations.index', [$account, 'tab' => 'api']) }}"
            class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition {{ $activeTab === 'api' ? 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' }}"
            @if ($activeTab === 'api') aria-current="page" @endif
        >
            {{ __('app.connections_tab_api') }}
        </a>
    @endif

    @if ($showProviderCategories)
        @foreach ($categories as $category)
            @php($isActive = $activeCategory === $category)
            <a
                href="{{ route('dashboard.accounts.integrations.show', [$account, $category]) }}"
                class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition {{ $isActive ? 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' }}"
                @if ($isActive) aria-current="page" @endif
            >
                {{ __($category->labelKey()) }}
            </a>
        @endforeach
    @endif
</nav>
