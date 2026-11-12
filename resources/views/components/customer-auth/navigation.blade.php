@if ($customer)
    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3">
        <div>{{ $slot }}</div>

        <details class="relative z-20 lg:hidden">
            <summary class="crm-focus flex h-11 w-11 cursor-pointer list-none items-center justify-center rounded-lg border border-stone-200 bg-white text-slate-800 shadow-xs [&::-webkit-details-marker]:hidden">
                <x-ui.icon name="menu" class="h-5 w-5" />
                <span class="sr-only">{{ __('app.customer_account_menu') }}</span>
            </summary>
            <nav class="absolute end-0 top-full mt-2 flex max-h-[65dvh] w-max min-w-56 max-w-[calc(100vw-2.5rem)] flex-col gap-2 overflow-y-auto rounded-xl border border-stone-200 bg-white p-2 shadow-crm" aria-label="{{ __('app.customer_account_menu') }}">
                @include('components.customer-auth._navigation-links')
                @include('components.customer-auth._account-actions')
            </nav>
        </details>

        <div class="hidden items-center gap-3 self-center lg:flex">
            @include('components.customer-auth._account-actions')
        </div>

        <nav class="col-span-full mt-4 hidden flex-wrap items-center gap-3 lg:flex" aria-label="{{ __('app.customer_account_menu') }}">
            @include('components.customer-auth._navigation-links')
        </nav>
    </div>
@else
    {{ $slot }}
@endif
