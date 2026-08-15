<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #0f172a;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12pt;
        }

        .ticket-page {
            height: 245mm;
            padding: 10mm;
            border: 1px solid #d6d3d1;
            border-radius: 8px;
            page-break-after: always;
            text-align: center;
        }

        .ticket-page:last-child {
            page-break-after: auto;
        }

        .studio {
            margin: 0;
            color: #047857;
            font-size: 10pt;
            font-weight: bold;
        }

        h1 {
            margin: 5mm 0 0;
            font-size: 24pt;
            line-height: 1.25;
        }

        .details {
            margin: 5mm auto 0;
            max-width: 155mm;
            color: #475569;
            line-height: 1.55;
        }

        .ticket-type {
            margin: 10mm 0 0;
            font-size: 17pt;
            font-weight: bold;
        }

        .qr {
            display: block;
            width: 82mm;
            height: 82mm;
            margin: 7mm auto 0;
        }

        .code {
            margin: 6mm 0 0;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .hint {
            margin: 3mm 0 0;
            color: #64748b;
            font-size: 10pt;
        }

        .number {
            margin: 10mm 0 0;
            color: #78716c;
            font-size: 9pt;
        }
    </style>
</head>
<body>
@foreach ($order->tickets as $ticket)
    <section class="ticket-page">
        <p class="studio">{{ $account->name }}</p>
        <h1>{{ $order->event->title }}</h1>
        <div class="details">
            <div>
                {{ $order->event->starts_at->timezone($order->event->timezone)->format('d.m.Y H:i') }}–{{ $order->event->ends_at->timezone($order->event->timezone)->format('H:i') }}
            </div>
            @if ($venue !== '')
                <div>{{ $venue }}</div>
            @endif
        </div>
        <p class="ticket-type">{{ $ticket->ticketType?->name ?: __('app.event_ticket') }}</p>
        <img class="qr" src="{{ $ticketQrCodes[$ticket->id] }}" alt="{{ __('app.event_ticket_qr') }}">
        <p class="code">{{ $ticket->code }}</p>
        <p class="hint">{{ __('app.event_ticket_present_at_door') }}</p>
        <p class="number">{{ __('app.event_ticket') }} {{ $loop->iteration }} / {{ $loop->count }}</p>
    </section>
@endforeach
</body>
</html>
