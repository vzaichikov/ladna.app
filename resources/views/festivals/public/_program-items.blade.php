<ol class="space-y-2" role="list">
    @foreach ($items as $item)
        <li>
            @if ($item['starts_at'] && $item['ends_at'])
                @php
                    $start = \Illuminate\Support\Carbon::parse($item['starts_at'])->timezone($edition->timezone);
                    $end = \Illuminate\Support\Carbon::parse($item['ends_at'])->timezone($edition->timezone);
                @endphp
                <div class="festival-page festival-border grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-3 rounded-xl border px-3 py-3 sm:gap-x-5 sm:px-4">
                    <div class="festival-muted whitespace-nowrap text-xs tabular-nums sm:text-sm">
                        @if ($showDates)
                            <span class="block">{{ $start->translatedFormat('j M') }}</span>
                        @endif
                        <time datetime="{{ $item['starts_at'] }}">{{ $start->format('H:i') }}</time><span aria-hidden="true">–</span><time datetime="{{ $item['ends_at'] }}">{{ $end->format('H:i') }}</time>
                    </div>
                    <div class="wrap-break-word">
                        <div class="font-semibold">
                            <x-festivals.performance-number :number="$item['category_number']" class="festival-muted" />{{ $item['name'] }}
                        </div>
                        @if ($item['category'] || $item['type'] === 'rehearsal')
                            <p class="festival-muted mt-1 text-xs sm:text-sm">{{ $item['category'] ?: $item['type_label'] }}</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="festival-border rounded-xl border px-3 py-2 font-semibold wrap-break-word sm:px-4">{{ $item['name'] }}</div>
            @endif

            @if ($item['children'] !== [])
                <div class="festival-border mt-2 border-s ps-2 sm:ps-3">
                    @include('festivals.public._program-items', ['items' => $item['children']])
                </div>
            @endif
        </li>
    @endforeach
</ol>
