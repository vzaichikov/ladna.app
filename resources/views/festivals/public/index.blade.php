@extends('layouts.public', ['hideAppFooter' => true])

@section('title', __('app.festivals').' - '.$account->name)
@section('publicFooter')<x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />@endsection

@section('content')
<main class="min-h-screen bg-canvas text-slate-950"><section class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-12"><x-ui.public-studio-header :account="$account" /><header class="mt-10 max-w-3xl"><p class="text-sm font-semibold text-brand-700">{{ $account->name }}</p><h1 class="mt-2 text-4xl font-semibold sm:text-6xl">{{ __('app.festivals') }}</h1><p class="mt-4 text-lg leading-8 text-slate-600">{{ __('app.festivals_public_intro') }}</p></header>
    <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">@forelse($editions as $edition)<article class="flex flex-col rounded-3xl border border-stone-200 bg-white p-6 shadow-crm"><span class="text-sm font-semibold text-brand-700">{{ $edition->series->name }}</span><h2 class="mt-2 text-2xl font-semibold">{{ $edition->title }}</h2><p class="mt-3 text-sm leading-6 text-slate-500">{{ $edition->starts_at->timezone($edition->timezone)->format('d.m.Y H:i') }}<br>{{ $edition->venue_name }} · {{ $edition->venue_address }}</p>@if($edition->summary)<p class="mt-4 flex-1 text-sm leading-6 text-slate-600">{{ $edition->summary }}</p>@endif<x-ui.button :href="route('public.festivals.show', [$account->slug, $edition->slug])" class="mt-5">{{ __('app.more') }}</x-ui.button></article>@empty<div class="md:col-span-2 lg:col-span-3"><x-ui.empty-state icon="trophy">{{ __('app.festivals_public_empty') }}</x-ui.empty-state></div>@endforelse</div>
    @if($editions->hasPages())<div class="mt-8">{{ $editions->links() }}</div>@endif
</section></main>
@endsection
