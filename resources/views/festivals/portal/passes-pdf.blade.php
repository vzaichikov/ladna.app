<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #0f172a; font-family: DejaVu Sans, sans-serif; font-size: 12pt; }
        .pass-page { height: 245mm; padding: 10mm; border: 1px solid #d6d3d1; border-radius: 8px; page-break-after: always; text-align: center; }
        .pass-page:last-child { page-break-after: auto; }
        .studio { margin: 0; color: #047857; font-size: 10pt; font-weight: bold; }
        h1 { margin: 5mm 0 0; font-size: 24pt; line-height: 1.25; }
        .details { margin: 5mm auto 0; max-width: 155mm; color: #475569; line-height: 1.55; }
        .name { margin: 10mm 0 0; font-size: 17pt; font-weight: bold; }
        .type { margin: 2mm 0 0; color: #475569; }
        .qr { display: block; width: 82mm; height: 82mm; margin: 7mm auto 0; }
        .code { margin: 6mm 0 0; font-family: DejaVu Sans Mono, monospace; font-size: 16pt; font-weight: bold; letter-spacing: 1px; }
        .hint { margin: 3mm 0 0; color: #64748b; font-size: 10pt; }
    </style>
</head>
<body>
@foreach ($passes as $pass)
    <section class="pass-page">
        <p class="studio">{{ $account->name }}</p>
        <h1>{{ $festivalEdition->title }}</h1>
        <div class="details">
            @if ($festivalEdition->starts_at)<div>{{ $festivalEdition->starts_at->timezone($festivalEdition->timezone)->format('d.m.Y H:i') }}</div>@endif
            @if ($venue !== '')<div>{{ $venue }}</div>@endif
        </div>
        <p class="name">{{ $pass->participant->displayName() }}</p>
        <p class="type">{{ $pass->participant->member_type === \App\Enums\FestivalTeamMemberType::Helper ? __('app.festival_helper_pass') : __('app.festival_participant_pass') }}</p>
        <img class="qr" src="{{ $qrCodes[$pass->id] }}" alt="{{ __('app.festival_ticket_qr') }}">
        <p class="code">{{ $pass->code }}</p>
        <p class="hint">{{ __('app.festival_ticket_present_at_entrance') }}</p>
    </section>
@endforeach
</body>
</html>
