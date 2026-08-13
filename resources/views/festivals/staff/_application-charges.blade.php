<section
    data-festival-application-fragment
    data-festival-application-fragment-key="charges-{{ $entry->id }}"
>
    @php
        $festivalFiscalizationEnabled = app(\App\Support\Fiscalization\FiscalizationAvailability::class)->enabledForAccount($account);
    @endphp
    <h3 class="font-semibold text-slate-950">{{ __('app.festival_payments') }}</h3>
    <div class="mt-3 flex flex-col gap-3">
        @forelse ($entry->charges as $charge)
            @include('festivals.staff._application-charge-review', compact('account', 'edition', 'charge', 'festivalFiscalizationEnabled'))
        @empty
            <p class="text-sm text-slate-500">{{ __('app.festival_no_payments') }}</p>
        @endforelse
    </div>
</section>
