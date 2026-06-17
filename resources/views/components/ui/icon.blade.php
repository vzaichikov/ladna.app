@props([
    'name' => 'sparkles',
    'class' => 'h-5 w-5',
])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('dashboard')
            <path d="M4 13h6V4H4z" />
            <path d="M14 20h6V4h-6z" />
            <path d="M4 20h6v-3H4z" />
            @break

        @case('accounts')
            <path d="M7 20a5 5 0 0 1 10 0" />
            <circle cx="12" cy="8" r="4" />
            <path d="M18 10a3 3 0 0 1 3 3" />
            <path d="M6 10a3 3 0 0 0-3 3" />
            @break

        @case('locations')
        @case('map-pin')
            <path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z" />
            <circle cx="12" cy="10" r="2.5" />
            @break

        @case('rooms')
            <path d="M4 20V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14" />
            <path d="M8 20v-7h8v7" />
            <path d="M9 8h.01" />
            <path d="M15 8h.01" />
            @break

        @case('directions')
            <path d="M12 3l8 8-8 10-8-10z" />
            <path d="M12 3v18" />
            <path d="M4 11h16" />
            @break

        @case('class-types')
            <path d="M12 3v4" />
            <path d="M12 17v4" />
            <path d="M3 12h4" />
            <path d="M17 12h4" />
            <circle cx="12" cy="12" r="5" />
            @break

        @case('class-pass-plans')
            <path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4Z" />
            <path d="M9 9h6" />
            <path d="M9 15h4" />
            @break

        @case('trainers')
        @case('user')
            <circle cx="12" cy="8" r="4" />
            <path d="M5 21a7 7 0 0 1 14 0" />
            @break

        @case('schedule')
        @case('calendar')
            <path d="M7 3v3" />
            <path d="M17 3v3" />
            <path d="M4 8h16" />
            <rect x="4" y="5" width="16" height="16" rx="2" />
            @break

        @case('generated-classes')
            <rect x="5" y="4" width="14" height="16" rx="2" />
            <path d="M9 9h6" />
            <path d="M9 13h4" />
            <path d="M8 17l1.5 1.5L13 15" />
            @break

        @case('platform')
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18" />
            <path d="M12 3a14 14 0 0 1 0 18" />
            <path d="M12 3a14 14 0 0 0 0 18" />
            @break

        @case('settings')
        @case('integrations')
            <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" />
            <path d="M19.4 15a1.8 1.8 0 0 0 .36 1.98l.05.05a2 2 0 1 1-2.83 2.83l-.05-.05a1.8 1.8 0 0 0-1.98-.36 1.8 1.8 0 0 0-1.1 1.65V21a2 2 0 1 1-4 0v-.1A1.8 1.8 0 0 0 8.7 19.25a1.8 1.8 0 0 0-1.98.36l-.05.05a2 2 0 1 1-2.83-2.83l.05-.05A1.8 1.8 0 0 0 4.25 14.8 1.8 1.8 0 0 0 2.6 13.7H2.5a2 2 0 1 1 0-4h.1A1.8 1.8 0 0 0 4.25 8.55a1.8 1.8 0 0 0-.36-1.98l-.05-.05a2 2 0 1 1 2.83-2.83l.05.05A1.8 1.8 0 0 0 8.7 4.1 1.8 1.8 0 0 0 9.8 2.45V2.35a2 2 0 1 1 4 0v.1a1.8 1.8 0 0 0 1.15 1.65 1.8 1.8 0 0 0 1.98-.36l.05-.05a2 2 0 1 1 2.83 2.83l-.05.05a1.8 1.8 0 0 0-.36 1.98 1.8 1.8 0 0 0 1.65 1.1h.1a2 2 0 1 1 0 4h-.1A1.8 1.8 0 0 0 19.4 15Z" />
            @break

        @case('building')
            <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16" />
            <path d="M9 21v-5h6v5" />
            <path d="M9 7h.01" />
            <path d="M15 7h.01" />
            <path d="M9 11h.01" />
            <path d="M15 11h.01" />
            @break

        @case('plus')
            <path d="M12 5v14" />
            <path d="M5 12h14" />
            @break

        @case('edit')
            <path d="M12 20h9" />
            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
            @break

        @case('trash')
            <path d="M3 6h18" />
            <path d="M8 6V4h8v2" />
            <path d="M19 6l-1 15H6L5 6" />
            <path d="M10 11v6" />
            <path d="M14 11v6" />
            @break

        @case('external')
            <path d="M14 4h6v6" />
            <path d="M10 14 20 4" />
            <path d="M20 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5" />
            @break

        @case('globe')
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18" />
            <path d="M12 3a13 13 0 0 1 0 18" />
            <path d="M12 3a13 13 0 0 0 0 18" />
            @break

        @case('menu')
            <path d="M4 6h16" />
            <path d="M4 12h16" />
            <path d="M4 18h16" />
            @break

        @case('close')
            <path d="M18 6 6 18" />
            <path d="m6 6 12 12" />
            @break

        @case('search')
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
            @break

        @case('bell')
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
            <path d="M10 21h4" />
            @break

        @case('logout')
            <path d="M10 17l5-5-5-5" />
            <path d="M15 12H3" />
            <path d="M21 4v16" />
            @break

        @case('chevron-right')
            <path d="m9 18 6-6-6-6" />
            @break

        @case('chevron-down')
            <path d="m6 9 6 6 6-6" />
            @break

        @case('arrow-left')
            <path d="m12 19-7-7 7-7" />
            <path d="M19 12H5" />
            @break

        @default
            <path d="M12 3l1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7z" />
            <path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z" />
    @endswitch
</svg>
