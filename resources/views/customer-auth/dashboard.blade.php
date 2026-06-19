@extends('layouts.public')

@section('title', __('app.customer_login').' - '.$account->name)

@section('content')
    <main class="flex min-h-screen items-center justify-center bg-canvas px-5 py-10">
        <section class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <x-ui.app-logo />
            </a>
            <h1 class="mt-8 text-2xl font-semibold text-slate-950">{{ __('app.customer_login') }}</h1>
            <p class="mt-3 text-sm leading-6 text-slate-500">{{ __('app.customer_portal_stub') }}</p>
        </section>
    </main>
@endsection
