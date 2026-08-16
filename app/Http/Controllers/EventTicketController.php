<?php

namespace App\Http\Controllers;

use App\Enums\EventOrderSource;
use App\Enums\EventTicketStatus;
use App\Models\Account;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventTicketController extends Controller
{
    public function index(Request $request, Account $account, Event $event): View
    {
        $this->authorizeManagement($request, $account, $event);
        $search = $request->string('q')->trim()->toString();
        $ticketTypeId = $request->integer('ticket_type') ?: null;
        $status = in_array($request->query('status'), array_column(EventTicketStatus::cases(), 'value'), true)
            ? (string) $request->query('status')
            : null;
        $checkIn = in_array($request->query('check_in'), ['checked_in', 'not_checked_in'], true)
            ? (string) $request->query('check_in')
            : null;
        $source = in_array($request->query('source'), array_column(EventOrderSource::cases(), 'value'), true)
            ? (string) $request->query('source')
            : null;
        $tickets = $event->tickets()
            ->select([
                'id', 'event_id', 'event_order_id', 'event_ticket_type_id', 'code', 'status',
                'is_checked_in', 'checked_in_at', 'created_at',
            ])
            ->with([
                'ticketType:id,name',
                'order:id,order_id,source,buyer_name,buyer_email,buyer_phone,provider,issued_by,amount_cents,currency,created_at',
                'order.issuedBy:id,name',
            ])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($query) => $query
                    ->where('order_id', 'like', "%{$search}%")
                    ->orWhere('buyer_name', 'like', "%{$search}%")
                    ->orWhere('buyer_email', 'like', "%{$search}%")
                    ->orWhere('buyer_phone', 'like', "%{$search}%"))))
            ->when($ticketTypeId, fn ($query, int $id) => $query->where('event_ticket_type_id', $id))
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->when($checkIn === 'checked_in', fn ($query) => $query->where('is_checked_in', true))
            ->when($checkIn === 'not_checked_in', fn ($query) => $query->where('is_checked_in', false))
            ->when($source, fn ($query, string $value) => $query->whereHas('order', fn ($query) => $query->where('source', $value)))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('events.tickets.index', [
            'account' => $account,
            'event' => $event,
            'tickets' => $tickets,
            'ticketTypes' => $event->ticketTypes()->get(['event_ticket_types.id', 'event_ticket_types.name']),
            'statuses' => EventTicketStatus::cases(),
            'filters' => [
                'q' => $search,
                'ticket_type' => $ticketTypeId,
                'status' => $status,
                'check_in' => $checkIn,
                'source' => $source,
            ],
            'hasFilters' => $search !== '' || $ticketTypeId !== null || $status !== null || $checkIn !== null || $source !== null,
            'canIssue' => $event->isPublished()
                && ! $event->isCompleted()
                && $event->ticketTypes()->where('is_active', true)->exists(),
        ]);
    }

    private function authorizeManagement(Request $request, Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
    }
}
