<?php

namespace App\Http\Controllers;

use App\Actions\EventTicketScanner;
use App\Models\Account;
use App\Models\Event;
use App\Models\IntegrationSetting;
use App\Support\MoneyFormatter;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventScannerController extends Controller
{
    public function show(Request $request, Account $account, Event $event, PaymentGatewayRegistry $gateways): View
    {
        $this->authorizeScanner($request, $account, $event);
        $tickets = $event->tickets()
            ->select(['id', 'event_id', 'event_order_id', 'event_ticket_type_id', 'code', 'status', 'is_checked_in', 'checked_in_at'])
            ->with(['ticketType:id,name', 'order:id,buyer_name'])
            ->where('is_checked_in', true)
            ->latest('checked_in_at')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('events.scanner', [
            'account' => $account,
            'event' => $event,
            'tickets' => $tickets,
            ...($request->user()?->can('doorStaff', $account)
                ? ['entranceTools' => $this->entranceTools($account, $event, $gateways)]
                : []),
        ]);
    }

    public function scan(Request $request, Account $account, Event $event, EventTicketScanner $scanner): JsonResponse
    {
        $this->authorizeScanner($request, $account, $event);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'source' => ['nullable', 'in:qr,manual,door_list,guest_search,monitor,entrance_sale'],
            'confirm' => ['sometimes', 'boolean'],
        ]);
        $result = $scanner->checkIn(
            $event,
            $data['code'],
            $request->user(),
            $data['source'] ?? 'qr',
            $request->ip(),
            (bool) ($data['confirm'] ?? false),
        );
        $status = match ($result['state']) {
            'invalid' => 404,
            'already_checked_in' => 409,
            'wrong_event', 'cancelled_event', 'void' => 422,
            default => 200,
        };

        return response()->json($result, $status);
    }

    private function authorizeScanner(Request $request, Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_unless(
            $request->user()?->can('checkInEventTickets', $account)
                || $request->user()?->can('doorStaff', $account),
            403,
        );
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
