@extends('layouts.public')

@section('title', __('app.login').' - '.__('app.app_name'))

@section('content')
    <main class="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#FAF8F5] px-4 py-6 sm:px-6 lg:px-8">
        <div class="pointer-events-none absolute -left-32 top-8 h-72 w-72 rounded-full bg-[#DCCFF0]/45 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-28 bottom-12 h-80 w-80 rounded-full bg-[#E7DDC9]/60 blur-3xl"></div>

        <section class="relative grid w-full max-w-5xl overflow-hidden rounded-[28px] border border-[#E7DDC9]/80 bg-white shadow-[0_24px_80px_rgba(59,34,63,0.16)] lg:min-h-[620px] lg:grid-cols-[0.98fr_1fr]">
            <div class="relative isolate flex min-h-[300px] items-center justify-center overflow-hidden bg-[#3B223F] px-8 py-12 text-white lg:min-h-full">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_18%,rgba(167,138,185,0.28),transparent_34%),linear-gradient(145deg,#3B223F_0%,#2B1731_100%)]"></div>
                <svg class="absolute -bottom-20 -left-24 h-64 w-[620px] text-[#DCCFF0]/42 sm:h-72 sm:w-[720px] lg:-bottom-14" viewBox="0 0 720 280" fill="none" aria-hidden="true">
                    <path d="M-38 228C108 117 272 239 415 149C516 85 604 48 752 102" stroke="currentColor" stroke-width="1.25" />
                    <path d="M-42 216C112 105 276 228 421 138C525 75 615 39 758 91" stroke="currentColor" stroke-width="1.25" />
                    <path d="M-46 204C116 93 280 217 427 127C534 65 626 30 764 80" stroke="currentColor" stroke-width="1.25" />
                    <path d="M-50 192C120 81 284 206 433 116C543 55 637 21 770 69" stroke="currentColor" stroke-width="1.25" />
                    <path d="M-54 180C124 69 288 195 439 105C552 45 648 12 776 58" stroke="currentColor" stroke-width="1.25" />
                    <path d="M-58 168C128 57 292 184 445 94C561 35 659 3 782 47" stroke="currentColor" stroke-width="1.25" />
                    <path d="M-62 156C132 45 296 173 451 83C570 25 670 -6 788 36" stroke="currentColor" stroke-width="1.25" />
                    <path d="M-66 144C136 33 300 162 457 72C579 15 681 -15 794 25" stroke="currentColor" stroke-width="1.25" />
                </svg>
                <div class="relative flex flex-col items-center text-center">
                    <a href="{{ route('home') }}" class="flex items-center gap-4 rounded-2xl px-2 py-2 transition hover:bg-white/5">
                        <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[18px] bg-[#FAF8F5] p-2.5 shadow-[0_14px_34px_rgba(20,10,24,0.22)] ring-1 ring-white/70">
                            <img src="{{ asset('brand/ladna-mark.svg') }}" alt="" class="h-full w-full object-contain">
                        </span>
                        <span class="text-[2.75rem] font-semibold leading-none tracking-normal text-white">{{ __('app.app_name') }}</span>
                    </a>
                    <p class="mt-3 text-base font-medium leading-6 text-[#DCCFF0]">{{ __('app.app_tagline') }}</p>
                </div>
            </div>

            <div class="flex items-center justify-center px-6 py-10 sm:px-12 lg:px-16">
                <form method="POST" action="{{ route('login') }}" class="w-full max-w-[360px]">
                    @csrf
                    <div>
                        <h1 class="text-[1.72rem] font-semibold leading-tight text-[#2B2B2F]">{{ __('app.auth_welcome_back') }}</h1>
                        <p class="mt-2 text-sm font-medium text-slate-500">{{ __('app.auth_login_subtitle') }}</p>
                    </div>

                    <div class="mt-8 space-y-5">
                        <label class="block">
                            <span class="block text-xs font-semibold text-[#2B2B2F]">{{ __('app.email') }}</span>
                            <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder="{{ __('app.auth_email_placeholder') }}" class="mt-2 h-12 w-full rounded-[10px] border border-stone-200 bg-white px-4 text-sm text-slate-900 shadow-xs outline-none transition placeholder:text-slate-400 focus:border-[#A78AB9] focus:ring-3 focus:ring-[#DCCFF0]/70">
                            @error('email') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="block text-xs font-semibold text-[#2B2B2F]">{{ __('app.password') }}</span>
                            <input name="password" type="password" required autocomplete="current-password" class="mt-2 h-12 w-full rounded-[10px] border border-stone-200 bg-white px-4 text-sm text-slate-900 shadow-xs outline-none transition placeholder:text-slate-400 focus:border-[#A78AB9] focus:ring-3 focus:ring-[#DCCFF0]/70">
                            @error('password') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                            <input name="remember" type="checkbox" value="1" class="h-4 w-4 rounded border-stone-300 text-[#3B223F] focus:ring-[#A78AB9]">
                            {{ __('app.remember_me') }}
                        </label>

                        <button type="submit" class="inline-flex h-12 w-full items-center justify-center rounded-[10px] bg-[#3B223F] px-4 text-sm font-semibold text-white shadow-[0_12px_26px_rgba(59,34,63,0.22)] transition hover:bg-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
                            {{ __('app.login') }}
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </main>
@endsection
