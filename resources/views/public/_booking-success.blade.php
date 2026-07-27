<div class="space-y-4 outline-none" role="status" aria-live="polite" tabindex="-1" data-public-booking-success>
    <div class="flex items-start gap-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white">
            <x-ui.icon name="check" class="h-5 w-5" />
        </div>
        <div>
            <h3 class="text-lg font-semibold text-slate-950">{{ __('app.booking_confirmed_title') }}</h3>
            <p class="mt-1 text-sm leading-6 text-emerald-800">{{ __('app.booking_confirmed_copy') }}</p>
        </div>
    </div>

    @include('public._booking-selection-card', ['selection' => $selection])

    <div class="grid gap-3 sm:grid-cols-2">
        <x-ui.button :href="$cabinetUrl" variant="primary" class="w-full" data-public-booking-cabinet>
            <x-ui.icon name="user" class="h-4 w-4" />
            {{ __('app.go_to_customer_cabinet') }}
        </x-ui.button>
        <x-ui.button type="button" variant="secondary" class="w-full" data-public-booking-continue data-continue-url="{{ $continueUrl }}">
            <x-ui.icon name="calendar-days" class="h-4 w-4" />
            {{ __('app.continue_booking') }}
        </x-ui.button>
    </div>
</div>
