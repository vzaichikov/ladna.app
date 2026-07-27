<article class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
    <div class="flex items-start gap-3">
        <div class="flex h-14 w-16 shrink-0 flex-col items-center justify-center rounded-xl bg-ink-950 text-white">
            <span class="text-lg font-semibold leading-none">{{ explode(' ', $selection['timeLabel'])[0] }}</span>
            <span class="mt-1 text-[11px] text-slate-300">{{ $selection['durationLabel'] }}</span>
        </div>
        <div class="min-w-0 flex-1">
            <h2 class="text-lg font-semibold leading-snug text-slate-950">{{ $selection['title'] }}</h2>
            <div class="mt-2 space-y-1 text-sm text-slate-600">
                <div>{{ $selection['dateLabel'] }}</div>
                <div>{{ $selection['timeLabel'] }}</div>
                <div>{{ $selection['roomLabel'] }}</div>
                @if ($selection['trainerLabel'])
                    <div>{{ $selection['trainerLabel'] }}</div>
                @endif
            </div>
        </div>
    </div>
</article>
