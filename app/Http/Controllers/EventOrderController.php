<?php

namespace App\Http\Controllers;

use App\Actions\RecordEventCashEntry;
use App\Enums\EventOrderSource;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventCashEntry;
use App\Models\EventOrder;
use App\Models\EventTicket;
use App\Support\Fiscalization\FiscalizationAvailability;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EventOrderController extends Controller
{
    public function index(
        Request $request,
        Account $account,
        Event $event,
        FiscalizationAvailability $fiscalization,
    ): View {
        $this->ensureScope($account, $event);
        abort_unless($request->user()?->can('manageEvents', $account), 403);

        return view('events.orders', [
            'account' => $account,
            'event' => $event,
            'orders' => $event->orders()
                ->with([
                    'items',
                    'tickets' => fn ($query) => $query->with('orderItem')->orderBy('id'),
                    'emailDeliveries' => fn ($query) => $query->latest('id'),
                    'fiscalReceipt',
                    'issuedBy:id,name',
                ])
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'fiscalizationEnabled' => $fiscalization->enabledForAccount($account),
            'urgentRefundsCount' => $event->orders()->whereIn('status', [
                EventOrderStatus::PaidRequiresRefund->value,
                EventOrderStatus::RefundRequired->value,
            ])->count(),
        ]);
    }

    public function resend(
        Request $request,
        Account $account,
        Event $event,
        EventOrder $eventOrder,
        TransactionalMailDispatcher $mailDispatcher,
    ): RedirectResponse {
        $this->ensureScope($account, $event, $eventOrder);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        abort_if(
            $event->status === EventStatus::Cancelled
            || blank($eventOrder->buyer_email)
            || $eventOrder->tickets()->where('status', EventTicketStatus::Valid->value)->doesntExist(),
            422,
        );
        $mailDispatcher->eventTicketsIssued($eventOrder);

        return back()->with('status', __('app.event_tickets_resent'));
    }

    public function refund(
        Request $request,
        Account $account,
        Event $event,
        EventOrder $eventOrder,
        RecordEventCashEntry $cashEntries,
    ): RedirectResponse {
        $this->ensureScope($account, $event, $eventOrder);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($eventOrder, $request, $validated, $cashEntries): void {
            $eventOrder = EventOrder::query()->whereKey($eventOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($eventOrder->status, [
                EventOrderStatus::Paid,
                EventOrderStatus::RefundRequired,
                EventOrderStatus::PaidRequiresRefund,
            ], true), 422);
            $eventOrder->forceFill([
                'status' => EventOrderStatus::Refunded,
                'refunded_by' => $request->user()->id,
                'refunded_at' => now(),
                'refund_reason' => $validated['reason'],
            ])->save();
            $eventOrder->tickets()->update(['status' => 'refunded', 'is_checked_in' => false, 'checked_in_at' => null]);

            if ($eventOrder->source === EventOrderSource::Entrance && $eventOrder->provider === 'entrance_cash') {
                $cashEntries->execute(
                    $eventOrder,
                    $request->user(),
                    EventCashEntry::DirectionOut,
                    EventCashEntry::PurposeEntranceTicketRefund,
                    $validated['reason'],
                );
            }
        });

        return back()->with('status', __('app.event_refund_recorded'));
    }

    public function voidTicket(
        Request $request,
        Account $account,
        Event $event,
        EventOrder $eventOrder,
        EventTicket $eventTicket,
    ): RedirectResponse {
        $this->ensureScope($account, $event, $eventOrder);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        abort_unless(
            $eventTicket->account_id === $account->id
            && $eventTicket->event_id === $event->id
            && $eventTicket->event_order_id === $eventOrder->id,
            404,
        );
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($eventTicket, $request, $validated): void {
            $ticket = EventTicket::query()->whereKey($eventTicket->id)->lockForUpdate()->firstOrFail();
            abort_unless($ticket->status === EventTicketStatus::Valid, 422);
            $ticket->forceFill([
                'status' => EventTicketStatus::Voided,
                'is_checked_in' => false,
                'checked_in_at' => null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
                'void_reason' => $validated['reason'],
            ])->save();
        });

        return back()->with('status', __('app.event_ticket_voided'));
    }

    private function ensureScope(Account $account, Event $event, ?EventOrder $order = null): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_if($order && ($order->account_id !== $account->id || $order->event_id !== $event->id), 404);
    }
}
