<?php

namespace App\Http\Controllers;

use App\Actions\EventTicketScanner;
use App\Enums\EventOrderStatus;
use App\Enums\EventTicketStatus;
use App\Http\Requests\UndoTicketAdmissionRequest;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventCashEntry;
use App\Models\EventTicket;
use App\Models\IntegrationSetting;
use App\Support\MoneyFormatter;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventTicketOverviewController extends Controller
{
    public function show(Request $request, Account $account, Event $event, PaymentGatewayRegistry $gateways): View
    {
        $this->authorizeOverview($request, $account, $event);

        return view('events.attendance', [
            'account' => $account,
            'event' => $event,
            'overview' => $this->overview($event),
            'entranceTools' => $this->entranceTools($account, $event, $gateways),
        ]);
    }

    public function data(Request $request, Account $account, Event $event): JsonResponse
    {
        $this->authorizeOverview($request, $account, $event);

        return response()
            ->json($this->overview($event))
            ->header('Cache-Control', 'no-store, private');
    }

    public function undo(
        UndoTicketAdmissionRequest $request,
        Account $account,
        Event $event,
        EventTicket $eventTicket,
        EventTicketScanner $scanner,
    ): JsonResponse {
        $this->assertEventScope($account, $event);
        $result = $scanner->checkOut(
            $event,
            $eventTicket,
            $request->user(),
            $request->validated('reason'),
            $request->ip(),
        );

        return response()->json($result, $result['state'] === 'checked_out' ? 200 : 422)
            ->header('Cache-Control', 'no-store, private');
    }

    private function authorizeOverview(Request $request, Account $account, Event $event): void
    {
        $this->assertEventScope($account, $event);
        abort_unless($request->user()?->can('doorStaff', $account), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(Event $event): array
    {
        $tickets = $event->tickets()
            ->select(['id', 'event_id', 'event_order_id', 'event_ticket_type_id', 'code', 'status', 'is_checked_in', 'checked_in_at'])
            ->where('status', EventTicketStatus::Valid->value)
            ->whereHas('order', fn ($query) => $query->where('status', EventOrderStatus::Paid->value))
            ->with(['order:id,buyer_name,status', 'ticketType:id,name'])
            ->orderByDesc('is_checked_in')
            ->orderByDesc('checked_in_at')
            ->orderBy('code')
            ->get();
        $cashBalance = (int) $event->cashEntries()
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount_cents ELSE -amount_cents END), 0) as balance', [EventCashEntry::DirectionIn])
            ->value('balance');
        $cashHistory = $event->cashEntries()
            ->with('order:id,buyer_name')
            ->latest('occurred_at')
            ->latest('id')
            ->limit(20)
            ->get();
        $cashHistoryResult = $cashHistory->map(fn (EventCashEntry $entry): array => [
            'id' => $entry->id,
            'direction' => $entry->direction,
            'purpose' => $entry->purpose,
            'amount_cents' => $entry->amount_cents,
            'amount_label' => MoneyFormatter::format($entry->amount_cents, $entry->currency),
            'formatted' => MoneyFormatter::format($entry->amount_cents, $entry->currency),
            'guest_name' => $entry->order?->buyer_name,
            'actor' => $entry->actor_name,
            'reason' => $entry->reason,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
            'occurred_at_label' => $entry->occurred_at?->timezone($event->timezone)->format('d.m.Y H:i'),
        ])->all();
        $cashBalanceResult = [
            'amount_cents' => $cashBalance,
            'currency' => strtoupper($event->currency),
            'label' => MoneyFormatter::format($cashBalance, $event->currency),
        ];

        return [
            'total' => $tickets->count(),
            'passed' => $tickets->where('is_checked_in', true)->count(),
            'unpassed' => $tickets->where('is_checked_in', false)->count(),
            'waiting' => $tickets->where('is_checked_in', false)->count(),
            'updated_at_label' => now($event->timezone)->format('H:i:s'),
            'cash_balances' => [$cashBalanceResult],
            'cash_history' => $cashHistoryResult,
            'cash' => [
                ...$cashBalanceResult,
                'formatted' => $cashBalanceResult['label'],
                'history' => $cashHistoryResult,
            ],
            'tickets' => $tickets
                ->map(fn (EventTicket $ticket): array => [
                    'id' => $ticket->id,
                    'customer_name' => $ticket->order?->buyer_name ?? __('app.unknown'),
                    'code' => $ticket->code,
                    'type' => $ticket->ticketType?->name,
                    'passed' => $ticket->is_checked_in,
                    'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
                    'checked_in_at_label' => $ticket->checked_in_at?->timezone($event->timezone)->format('d.m.Y H:i'),
                    'undo_url' => route('dashboard.accounts.events.attendance.tickets.undo', [$event->account_id, $event, $ticket]),
                ])
                ->values()
                ->all(),
        ];
    }

    private function assertEventScope(Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
    }

    /** @return array<string, mixed> */
    private function entranceTools(Account $account, Event $event, PaymentGatewayRegistry $gateways): array
    {
        $providers = $gateways->availableSettingsFor($account);
        $ticketTypes = $event->ticketTypes()
            ->where('is_active', true)
            ->withSoldOrHeldQuantity()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($ticketType): array => [
                'id' => $ticketType->id,
                'name' => $ticketType->name,
                'price_label' => MoneyFormatter::format($ticketType->price_cents, $event->currency),
                'remaining' => $ticketType->remainingQuantity(),
            ]);

        return [
            'search_url' => route('dashboard.accounts.events.entrance.search', [$account, $event]),
            'cash_sale_url' => route('dashboard.accounts.events.entrance.cash', [$account, $event]),
            'card_sale_url' => route('dashboard.accounts.events.entrance.card', [$account, $event]),
            'ticket_types' => $ticketTypes,
            'payment_providers' => $providers->map(fn (IntegrationSetting $setting): array => [
                'value' => $setting->provider->value,
                'label' => config('integrations.providers.'.$setting->provider->value.'.label', $setting->provider->value),
            ]),
        ];
    }
}
