<div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
    <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.api_tokens') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.api_tokens_copy') }}</p>
        </div>

        <div class="mt-6 space-y-3">
            @forelse ($apiTokens as $apiToken)
                <article class="rounded-lg border border-stone-200 bg-slate-50 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-slate-950">{{ $apiToken->name }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.api_token_last_four', ['last_four' => $apiToken->last_four]) }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ __('app.last_used') }}: {{ \App\Support\DateTimePresenter::format($apiToken->last_used_at, $account) ?? __('app.never') }}</p>
                        </div>
                        <span class="{{ $apiToken->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                            {{ $apiToken->is_active ? __('app.active') : __('app.revoked') }}
                        </span>
                    </div>

                    @if ($apiToken->is_active)
                        <label class="mt-4 block">
                            <span class="crm-label">{{ __('app.api_token_value') }}</span>
                            <input value="{{ $apiToken->tokenValue() }}" readonly class="crm-field font-mono text-xs" data-copy-source>
                        </label>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ui.button type="button" variant="secondary" size="sm" data-copy-token>
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                {{ __('app.copy') }}
                            </x-ui.button>
                            <form method="POST" action="{{ route('dashboard.accounts.api-tokens.regenerate', [$account, $apiToken]) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">
                                    <x-ui.icon name="key" class="h-4 w-4" />
                                    {{ __('app.regenerate') }}
                                </x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('dashboard.accounts.api-tokens.destroy', [$account, $apiToken]) }}" data-confirm-delete>
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger" size="sm">
                                    <x-ui.icon name="trash" class="h-4 w-4" />
                                    {{ __('app.revoke') }}
                                </x-ui.button>
                            </form>
                        </div>
                    @endif
                </article>
            @empty
                <x-ui.empty-state :title="__('app.no_api_tokens')" icon="key" />
            @endforelse
        </div>
    </section>

    <form method="POST" action="{{ route('dashboard.accounts.api-tokens.store', $account) }}" class="h-fit rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf

        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.create_api_token') }}</h2>
        <label class="mt-5 block">
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="name" required value="{{ old('name') }}" class="crm-field" placeholder="{{ __('app.api_token_name_placeholder') }}">
            @error('name') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
        <x-ui.button type="submit" class="mt-5">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_api_token') }}
        </x-ui.button>
    </form>
</div>
