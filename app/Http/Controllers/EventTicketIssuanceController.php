<?php

namespace App\Http\Controllers;

use App\Actions\IssueManualEventTickets;
use App\Http\Requests\IssueEventTicketsRequest;
use App\Models\Account;
use App\Models\Event;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventTicketIssuanceController extends Controller
{
    public function create(Request $request, Account $account, Event $event): View
    {
        $this->authorizeManagement($request, $account, $event);
        abort_unless($event->isPublished() && ! $event->isCompleted(), 422);
        $ticketTypes = $event->ticketTypes()
            ->withSoldOrHeldQuantity()
            ->where('is_active', true)
            ->get();
        abort_if($ticketTypes->isEmpty(), 422);

        return view('events.tickets.issue', [
            'account' => $account,
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'paymentMethods' => IssueManualEventTickets::PAYMENT_METHODS,
        ]);
    }

    public function store(
        IssueEventTicketsRequest $request,
        Account $account,
        Event $event,
        IssueManualEventTickets $issueTickets,
        TransactionalMailDispatcher $mailDispatcher,
    ): RedirectResponse {
        $order = $issueTickets->execute(
            $account,
            $event,
            $request->user(),
            $request->validated(),
            app()->getLocale(),
        );

        if (filled($order->buyer_email)) {
            $mailDispatcher->eventTicketsIssued($order);
        }

        return redirect()->route('dashboard.accounts.events.tickets.index', [$account, $event])
            ->with('status', __('app.event_manual_tickets_issued'))
            ->with('issued_ticket_codes', $order->tickets->pluck('code')->all());
    }

    private function authorizeManagement(Request $request, Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
    }
}
