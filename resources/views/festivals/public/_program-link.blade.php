@if ($edition->has_public_program || $publicTimelineViews->isNotEmpty())
    <a
        href="{{ route('public.festivals.program', [$account->slug, $edition->slug]) }}"
        class="crm-focus inline-flex items-center justify-center gap-2 {{ $buttonClass }}"
        data-festival-program-link
        data-program-published="{{ $edition->has_public_program ? 'true' : 'false' }}"
    >
        <x-ui.icon name="calendar-days" class="h-4 w-4 shrink-0" />
        <span
            data-festival-program-link-label
            data-program-label="{{ __('app.festival_program_title') }}"
            data-timeline-label="{{ __('app.festival_timeline_title') }}"
        >{{ __($publicTimelineViews->isNotEmpty() ? 'app.festival_timeline_title' : 'app.festival_program_title') }}</span>
    </a>
@endif
