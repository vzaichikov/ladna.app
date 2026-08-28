@php
    $initials = mb_strtoupper(
        mb_substr((string) $participant->first_name, 0, 1)
        .mb_substr((string) $participant->last_name, 0, 1),
    );
    $hasPhoto = filled($participant->resolvedPhotoPath());
@endphp

<span class="relative flex {{ $class ?? 'h-14 w-14' }} shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-100 font-semibold text-brand-800 ring-1 ring-brand-200" aria-hidden="true">
    <span>{{ $initials }}</span>
    @if($hasPhoto)
        <img
            src="{{ route('festival.portal.participants.photo', [$account->slug, $participant]) }}"
            alt=""
            loading="lazy"
            class="absolute inset-0 h-full w-full object-cover"
            data-festival-team-avatar-image
        >
    @endif
</span>
