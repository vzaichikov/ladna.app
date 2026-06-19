@extends('layouts.public')

@section('title', __('app.customer_login').' - '.__('app.app_name'))

@section('content')
    <main class="flex min-h-screen items-center justify-center bg-canvas px-5 py-10">
        <section class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <x-ui.app-logo />
            </a>
            <h1 class="mt-8 text-2xl font-semibold text-slate-950">{{ __('app.customer_login') }}</h1>
            <p class="mt-3 text-sm leading-6 text-slate-500">{{ __('app.customer_login_stub') }}</p>
            <div class="mt-6 grid gap-3">
                <button disabled class="rounded-lg border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-500">{{ __('app.phone_login') }}</button>
                <button disabled class="rounded-lg border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-500">{{ __('app.google_login') }}</button>
            </div>
        </section>
    </main>
@endsection
