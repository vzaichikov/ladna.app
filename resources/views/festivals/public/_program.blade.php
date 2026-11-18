<div
    data-festival-timeline
    data-timeline-scope="public-program"
    data-timeline-fragment-url="{{ $programPollingUrl }}"
>
    <h1 class="mt-1 mb-6 text-4xl leading-tight font-semibold sm:mb-8 sm:text-5xl">
        {{ __($publicTimelineViews->isNotEmpty() ? 'app.festival_timeline_title' : 'app.festival_program_title') }}
    </h1>

    @if ($publicTimelineViews->isNotEmpty())
        @include('festivals.public._timeline-scenes')
    @elseif ($program !== [])
        @php($showDates = ! $edition->starts_at->timezone($edition->timezone)->isSameDay($edition->ends_at->timezone($edition->timezone)))
        <div class="space-y-6">
            @foreach ($program as $scene)
                <section class="festival-surface festival-border rounded-3xl border p-4 sm:p-7" aria-labelledby="festival-program-scene-{{ $loop->index }}">
                    <h2 id="festival-program-scene-{{ $loop->index }}" class="mb-4 text-xl font-semibold sm:text-2xl">{{ $scene['stage'] }}</h2>

                    @include('festivals.public._program-items', ['items' => $scene['items']])
                </section>
            @endforeach
        </div>
    @else
        <p class="festival-muted">{{ __('app.festival_program_unavailable') }}</p>
    @endif
</div>
