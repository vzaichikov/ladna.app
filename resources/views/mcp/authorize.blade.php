<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('app.mcp_authorize_title') }} — {{ config('app.name') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-[#F8F4FA] antialiased">
        <main class="flex min-h-screen items-center justify-center p-4">
            <section class="w-full max-w-lg rounded-2xl border border-violet-100 bg-white p-6 shadow-xl shadow-violet-950/10 sm:p-8">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-100 text-violet-800">
                    <x-ui.icon name="sparkles" class="h-7 w-7" />
                </div>

                <h1 class="mt-5 text-center text-2xl font-semibold text-slate-950">{{ __('app.mcp_authorize_heading', ['assistant' => $client->name]) }}</h1>
                <p class="mt-3 text-center text-sm leading-6 text-slate-600">{{ __('app.mcp_authorize_copy', ['studio' => $account->name]) }}</p>

                <div class="mt-6 space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-4 text-sm leading-6 text-slate-700">
                    <div class="flex gap-3"><x-ui.icon name="check" class="mt-1 h-4 w-4 shrink-0 text-emerald-600" /><span>{{ __('app.mcp_authorize_read_only') }}</span></div>
                    <div class="flex gap-3"><x-ui.icon name="check" class="mt-1 h-4 w-4 shrink-0 text-emerald-600" /><span>{{ __('app.mcp_authorize_permissions') }}</span></div>
                    <div class="flex gap-3"><x-ui.icon name="check" class="mt-1 h-4 w-4 shrink-0 text-emerald-600" /><span>{{ __('app.mcp_authorize_disconnect') }}</span></div>
                </div>

                <p class="mt-4 text-center text-xs text-slate-500">{{ __('app.mcp_authorize_signed_in_as', ['email' => $user->email]) }}</p>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <x-ui.button type="submit" variant="secondary" class="w-full justify-center">{{ __('app.cancel') }}</x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <x-ui.button type="submit" class="w-full justify-center">{{ __('app.mcp_authorize_connect') }}</x-ui.button>
                    </form>
                </div>
            </section>
        </main>
    </body>
</html>
