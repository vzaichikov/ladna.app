<?php

namespace App\Http\Controllers;

use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EventScannerController extends Controller
{
    public function show(Request $request, Account $account, Event $event): View
    {
        $this->authorizeScanner($request, $account, $event);
        $search = trim($request->string('search')->toString());
        $tickets = $event->tickets()
            ->with(['ticketType', 'order:id,buyer_name'])
            ->when($search !== '', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('code', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($query) => $query->where('buyer_name', 'like', "%{$search}%"))))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return view('events.scanner', [
            'account' => $account,
            'event' => $event,
            'tickets' => $tickets,
            'search' => $search,
        ]);
    }

    public function scan(Request $request, Account $account, Event $event): JsonResponse
    {
        $this->authorizeScanner($request, $account, $event);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'source' => ['nullable', 'in:qr,manual,door_list'],
        ]);
        $value = trim($validated['code']);
        $ticket = EventTicket::query()
            ->where(fn ($query) => $query
                ->where('token_hash', hash('sha256', $value))
                ->orWhere('code', strtoupper($value)))
            ->first();

        if (! $ticket) {
            return response()->json(['state' => 'invalid', 'message' => __('app.event_scan_invalid')], 404);
        }

        if ($ticket->event_id !== $event->id) {
            return response()->json(['state' => 'wrong_event', 'message' => __('app.event_scan_wrong_event')], 422);
        }

        return DB::transaction(function () use ($ticket, $event, $request, $validated): JsonResponse {
            $ticket = EventTicket::query()->with(['order', 'ticketType'])->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($event->status === EventStatus::Cancelled || $event->status === EventStatus::Archived) {
                return response()->json(['state' => 'cancelled_event', 'message' => __('app.event_scan_cancelled')], 422);
            }

            if ($ticket->status !== EventTicketStatus::Valid || in_array($ticket->order->status, [
                EventOrderStatus::Refunded,
                EventOrderStatus::RefundRequired,
                EventOrderStatus::PaidRequiresRefund,
            ], true)) {
                return response()->json(['state' => 'void', 'message' => __('app.event_scan_void')], 422);
            }

            if ($ticket->is_checked_in) {
                $last = $ticket->checkIns()->latest('occurred_at')->first();

                return response()->json([
                    'state' => 'already_checked_in',
                    'message' => __('app.event_scan_duplicate'),
                    'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
                    'operator' => $last?->actor_name,
                ], 409);
            }

            $ticket->forceFill(['is_checked_in' => true, 'checked_in_at' => now()])->save();
            $this->audit($ticket, $request, 'check_in', $validated['source'] ?? 'qr');

            return response()->json([
                'state' => 'checked_in',
                'message' => __('app.event_scan_success'),
                'ticket' => ['code' => $ticket->code, 'type' => $ticket->ticketType?->name],
            ]);
        }, 3);
    }

    public function checkOut(Request $request, Account $account, Event $event, EventTicket $eventTicket): JsonResponse
    {
        $this->authorizeScanner($request, $account, $event);
        abort_unless($eventTicket->event_id === $event->id && $eventTicket->account_id === $account->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return DB::transaction(function () use ($eventTicket, $request, $validated): JsonResponse {
            $ticket = EventTicket::query()->whereKey($eventTicket->id)->lockForUpdate()->firstOrFail();

            if (! $ticket->is_checked_in) {
                return response()->json(['state' => 'not_checked_in', 'message' => __('app.event_scan_not_checked_in')], 422);
            }

            $ticket->forceFill(['is_checked_in' => false, 'checked_in_at' => null])->save();
            $this->audit($ticket, $request, 'check_out', 'door_list', $validated['reason']);

            return response()->json(['state' => 'checked_out', 'message' => __('app.event_scan_checked_out')]);
        }, 3);
    }

    private function authorizeScanner(Request $request, Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_unless($request->user()?->can('checkInEventTickets', $account), 403);
    }

    private function audit(EventTicket $ticket, Request $request, string $action, string $source, ?string $reason = null): void
    {
        $ticket->checkIns()->create([
            'account_id' => $ticket->account_id,
            'event_id' => $ticket->event_id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'source' => $source,
            'actor_name' => $request->user()?->name ?? __('app.unknown'),
            'actor_email' => $request->user()?->email,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
