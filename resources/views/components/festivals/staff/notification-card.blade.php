@props([
    'notification',
    'timezone',
    'showRecipient' => true,
    'showContext' => false,
])

@php
    $badgeClass = match($notification->status) {
        \App\Enums\FestivalNotificationStatus::Pending => 'bg-amber-100 text-amber-800',
        \App\Enums\FestivalNotificationStatus::WaitingForSmsCredit => 'bg-orange-100 text-orange-800',
        \App\Enums\FestivalNotificationStatus::Sending => 'bg-sky-100 text-sky-800',
        \App\Enums\FestivalNotificationStatus::Sent => 'bg-emerald-100 text-emerald-800',
        \App\Enums\FestivalNotificationStatus::Failed => 'bg-rose-100 text-rose-800',
        \App\Enums\FestivalNotificationStatus::Cancelled => 'bg-stone-100 text-stone-700',
    };
@endphp

<article {{ $attributes->merge(['class' => 'rounded-2xl border border-stone-200 bg-white p-5 shadow-crm']) }}>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                @if ($showRecipient)
                    <strong class="text-slate-950">{{ $notification->recipient_name ?: __('app.unknown') }}</strong>
                @endif
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_notification_channel_'.$notification->channel->value) }}</span>
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">{{ __('app.festival_notification_status_'.$notification->status->value) }}</span>
            </div>
            @if ($showRecipient)
                <p class="mt-1 break-words text-sm text-slate-600">{{ $notification->channel === \App\Enums\FestivalNotificationChannel::Email ? $notification->recipient_email : ($notification->recipient_phone ?: __('app.not_set')) }}</p>
            @endif
        </div>
        <time class="shrink-0 text-xs text-slate-500" datetime="{{ $notification->created_at->toAtomString() }}">{{ $notification->created_at->timezone($timezone)->format('d.m.Y H:i') }}</time>
    </div>

    @if ($showContext)
        <div class="mt-4 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-700">
            <span class="font-semibold">{{ $notification->edition?->title ?: __('app.festival_notification_unknown_edition') }}</span>
            @if ($notification->entry)
                <span> · {{ $notification->entry->entry_name }} ({{ $notification->entry->code }})</span>
            @endif
        </div>
    @endif

    <div class="mt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_notification_type_'.$notification->type->value) }}</p>
        <h3 class="mt-1 font-semibold text-slate-950">{{ $notification->subject ?: __('app.festival_notification_subject') }}</h3>
        <p class="mt-2 line-clamp-3 whitespace-pre-line break-words text-sm text-slate-600">{{ $notification->text }}</p>
    </div>

    @if ($notification->text)
        <details class="mt-3 rounded-xl bg-slate-50 p-3">
            <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('app.festival_show_full_text') }}</summary>
            <p class="mt-3 whitespace-pre-line break-words text-sm text-slate-700">{{ $notification->text }}</p>
        </details>
    @endif

    @if ($notification->failure_reason)
        <p class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700"><strong>{{ __('app.festival_failure_reason') }}:</strong> {{ $notification->failure_reason }}</p>
    @endif
</article>
