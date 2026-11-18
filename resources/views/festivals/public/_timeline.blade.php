@if ($timelineWithinDates)
    <div
        @if ($publicTimelineViews->isNotEmpty()) data-festival-timeline @else data-festival-timeline-poller @endif
        data-timeline-scope="public"
        data-timeline-fragment-url="{{ $timelinePollingUrl }}"
        class="festival-live-timeline"
    >
        @if ($publicTimelineViews->isNotEmpty())
            @include('festivals.public._timeline-scenes')
        @endif
    </div>
@endif
