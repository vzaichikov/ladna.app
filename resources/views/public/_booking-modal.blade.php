<div
    class="fixed inset-0 z-50 {{ ($autoOpen ?? false) ? 'flex' : 'hidden' }} items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-labelledby="public-booking-modal-title"
    data-public-booking-modal
    data-booking-id="{{ $selection['scheduledClassId'] ?? '' }}"
    @if ($autoOpen ?? false) data-auto-open="true" @endif
>
    <div class="flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-2xl border border-stone-200 bg-canvas shadow-2xl sm:max-w-xl">
        <header class="flex shrink-0 items-center justify-between gap-4 border-b border-stone-200 bg-white px-4 py-3 sm:px-5 sm:py-4">
            <h2 id="public-booking-modal-title" class="text-lg font-semibold text-slate-950">
                {{ __('app.booking_confirmation') }}
            </h2>
            <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-public-booking-close />
        </header>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4 sm:p-5" data-public-booking-modal-body>
            @include('public._booking-selection-card', ['selection' => $selection])
            @include('public._booking-form', [
                'account' => $account,
                'location' => $location,
                'customer' => $customer,
                'selection' => $selection,
                'allowsGuestBooking' => $allowsGuestBooking,
                'isModal' => true,
                'returnUrl' => $returnUrl,
            ])
        </div>
    </div>
</div>
