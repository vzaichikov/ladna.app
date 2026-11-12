@props(['account', 'customer', 'title'])

<x-customer-auth.navigation :account="$account" :customer="$customer">
    <header class="flex items-center gap-4">
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
            <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
        </span>
        <div class="min-w-0 wrap-break-word">
            <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
            <h1 class="text-2xl font-semibold text-slate-950">{{ $title }}</h1>
        </div>
    </header>
</x-customer-auth.navigation>
