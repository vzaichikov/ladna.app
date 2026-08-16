<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('app.entrance_payment_poster_title') }} - {{ $occasion->title }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        @media print {
            [data-print-action] { display: none !important; }
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-stone-100 text-slate-950 print:bg-white">
    <main class="mx-auto flex min-h-[calc(297mm-24mm)] w-full max-w-[186mm] flex-col justify-between overflow-hidden bg-white p-8 shadow-2xl print:min-h-[273mm] print:p-0 print:shadow-none sm:p-12">
        <div>
            <div class="flex items-center justify-between gap-6 border-b-2 border-slate-950 pb-5">
                <div class="min-w-0">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-700">{{ $account->name }}</p>
                    <h1 class="mt-2 text-4xl font-semibold leading-tight sm:text-5xl">{{ $occasion->title }}</h1>
                </div>
                @if ($account->logo_url ?? null)
                    <img src="{{ $account->logo_url }}" alt="" class="h-16 w-16 shrink-0 rounded-2xl object-contain">
                @endif
            </div>

            <div class="mx-auto mt-12 max-w-xl text-center">
                <p class="text-3xl font-semibold leading-tight">{{ __('app.entrance_scan_to_buy') }}</p>
                <p class="mt-3 text-lg leading-8 text-slate-600">{{ __('app.entrance_poster_help') }}</p>
                <div class="mx-auto mt-8 w-fit rounded-[2rem] border-4 border-slate-950 bg-white p-5">
                    <img src="{{ $qrDataUri }}" alt="{{ __('app.entrance_payment_qr_alt') }}" class="aspect-square w-72 max-w-full">
                </div>
                <p class="mt-6 break-all font-mono text-xs text-slate-500">{{ $checkoutUrl }}</p>
            </div>
        </div>

        <div>
            @if (($occasionDateLabel ?? null) || ($venueLabel ?? null))
                <div class="grid gap-3 border-y border-stone-300 py-5 text-center sm:grid-cols-2">
                    @if ($occasionDateLabel ?? null)<p class="font-semibold">{{ $occasionDateLabel }}</p>@endif
                    @if ($venueLabel ?? null)<p class="font-semibold">{{ $venueLabel }}</p>@endif
                </div>
            @endif
            <p class="mt-6 text-center text-sm text-slate-500">{{ __('app.entrance_poster_payment_note') }}</p>
        </div>
    </main>

    <button type="button" onclick="window.print()" class="fixed bottom-5 right-5 inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-xl hover:bg-brand-700 crm-focus" data-print-action>
        {{ __('app.print') }}
    </button>
</body>
</html>
