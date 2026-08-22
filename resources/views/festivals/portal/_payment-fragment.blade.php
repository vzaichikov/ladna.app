<div data-festival-payment-fragment>
    @if($paymentGroups->isNotEmpty())
        <div class="mt-6 border-t border-stone-200 pt-5">
            <h3 class="text-lg font-semibold">{{ __('app.festival_payments') }}</h3>
            <div class="mt-3 space-y-3">
                @foreach($paymentGroups as $paymentGroup)
                    @include('festivals.portal._charge-card', [
                        'account' => $account,
                        'entry' => $entry,
                        'selectedState' => $selectedState,
                        'providers' => $providers,
                        'paymentGroup' => $paymentGroup,
                    ])
                @endforeach
            </div>
        </div>
    @endif
</div>
