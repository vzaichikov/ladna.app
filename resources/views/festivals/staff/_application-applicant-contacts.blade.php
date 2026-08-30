@php
    $portalUser = $entry->portalUser;
    $telegramUrl = \App\Rules\FestivalSocialLink::telegram()->url($portalUser->telegram_contact);
    $instagramUrl = \App\Rules\FestivalSocialLink::instagram()->url($portalUser->instagram_url);
    $contacts = [
        [
            'label' => __('app.full_name'),
            'value' => $portalUser->displayName(),
            'href' => route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]),
        ],
        [
            'label' => __('app.phone'),
            'value' => $portalUser->phone,
            'href' => filled($portalUser->phone) ? 'tel:'.$portalUser->phone : null,
        ],
        [
            'label' => __('app.email'),
            'value' => $portalUser->email,
            'href' => filled($portalUser->email) ? 'mailto:'.$portalUser->email : null,
        ],
        [
            'label' => __('app.festival_telegram_contact_label'),
            'value' => $portalUser->telegram_contact,
            'href' => $telegramUrl,
        ],
        [
            'label' => __('app.festival_instagram_contact_label'),
            'value' => $portalUser->instagram_url,
            'href' => $instagramUrl,
        ],
    ];
@endphp

<section class="rounded-xl border border-stone-200 bg-white p-4" data-festival-applicant-contacts>
    <h3 class="font-semibold text-slate-950">{{ __('app.festival_profile_contact_details') }}</h3>
    <dl class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach($contacts as $contact)
            <div class="min-w-0 rounded-lg bg-slate-50 px-3 py-2.5">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $contact['label'] }}</dt>
                <dd class="mt-1 min-w-0 text-sm font-semibold text-slate-950">
                    @if($contact['href'])
                        <a href="{{ $contact['href'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex max-w-full items-center gap-1.5 text-brand-700 transition hover:text-brand-800">
                            <span class="truncate">{{ $contact['value'] }}</span>
                            <x-ui.icon name="external-link" class="h-3.5 w-3.5 shrink-0" />
                        </a>
                    @elseif(filled($contact['value']))
                        <span class="break-words">{{ $contact['value'] }}</span>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</section>
