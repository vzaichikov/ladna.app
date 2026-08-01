<div class="flex flex-wrap gap-2">
    @forelse ($amounts as $currency => $amountCents)
        <span @class([
            'rounded-full px-3 py-1.5 text-sm font-semibold tabular-nums',
            'bg-emerald-100 text-emerald-800' => $amountCents >= 0,
            'bg-rose-100 text-rose-800' => $amountCents < 0,
        ])>
            {{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}
        </span>
    @empty
        <span class="text-sm font-semibold text-slate-400">{{ __('app.finance_no_amounts') }}</span>
    @endforelse
</div>
