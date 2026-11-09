<?php

namespace App\Support\Festivals;

use App\Models\Account;
use App\Models\FestivalTicketOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as Document;

class FestivalTicketPdf
{
    public function __construct(private readonly FestivalQrToken $qr) {}

    /** Render the caller's authorized, valid venue tickets. */
    public function render(Account $account, FestivalTicketOrder $order): Document
    {
        $qrCodes = $order->tickets->mapWithKeys(fn ($ticket): array => [$ticket->id => $this->qr->dataUri($ticket)]);
        $venue = collect([$order->edition->venue_name, $order->edition->venue_address])->filter()->join(' · ');

        return Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ], true)
            ->setPaper('a4', 'portrait')
            ->loadView('festivals.public.tickets-pdf', compact('account', 'order', 'qrCodes', 'venue'));
    }
}
