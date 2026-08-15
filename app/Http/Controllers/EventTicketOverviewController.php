<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventTicketOverviewController extends Controller
{
    public function show(Request $request, Account $account, Event $event): View
    {
        $this->authorizeOverview($request, $account, $event);

        return view('events.attendance', [
            'account' => $account,
            'event' => $event,
            'overview' => $this->overview($event),
        ]);
    }

    public function data(Request $request, Account $account, Event $event): JsonResponse
    {
        $this->authorizeOverview($request, $account, $event);

        return response()
            ->json($this->overview($event))
            ->header('Cache-Control', 'no-store, private');
    }

    private function authorizeOverview(Request $request, Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_unless($request->user()?->can('checkInEventTickets', $account), 403);
    }

    /**
     * @return array{total: int, passed: int, tickets: list<array{id: int, customer_name: string, code: string, passed: bool}>}
     */
    private function overview(Event $event): array
    {
        $tickets = $event->tickets()
            ->select(['id', 'event_id', 'event_order_id', 'code', 'is_checked_in'])
            ->with('order:id,buyer_name')
            ->orderBy('code')
            ->get();

        return [
            'total' => $tickets->count(),
            'passed' => $tickets->where('is_checked_in', true)->count(),
            'tickets' => $tickets
                ->map(fn (EventTicket $ticket): array => [
                    'id' => $ticket->id,
                    'customer_name' => $ticket->order?->buyer_name ?? __('app.unknown'),
                    'code' => $ticket->code,
                    'passed' => $ticket->is_checked_in,
                ])
                ->values()
                ->all(),
        ];
    }
}
