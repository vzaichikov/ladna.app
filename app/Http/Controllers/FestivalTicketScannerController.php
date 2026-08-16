<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalTicketScanner;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalTicket;
use App\Models\IntegrationSetting;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\MoneyFormatter;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalTicketScannerController extends Controller
{
    public function show(
        Request $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalWorkspaceAccess $workspaceAccess,
        PaymentGatewayRegistry $gateways,
    ): View {
        $this->authorizeScanner($request, $account, $festivalEdition);
        $search = trim($request->string('search')->toString());
        $tickets = FestivalTicket::query()->where('festival_edition_id', $festivalEdition->id)->with(['admissionType', 'order:id,buyer_name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhereHas('order', fn ($query) => $query->where('buyer_name', 'like', "%{$search}%"))))
            ->orderBy('code')->paginate(50)->withQueryString();

        return view('festivals.staff.scanner', compact('account', 'festivalEdition', 'tickets', 'search') + [
            'workspacePermissions' => $workspaceAccess->permissions($request->user(), $account, $festivalEdition),
            ...($request->user()?->can('doorStaff', $account)
                ? ['entranceTools' => $this->entranceTools($account, $festivalEdition, $gateways)]
                : []),
        ]);
    }

    public function scan(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalTicketScanner $scanner): JsonResponse
    {
        $this->authorizeScanner($request, $account, $festivalEdition);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'source' => ['nullable', 'in:qr,manual,door_list,guest_search,monitor,entrance_sale'],
            'confirm' => ['sometimes', 'boolean'],
        ]);
        $result = $scanner->checkIn(
            $festivalEdition,
            $data['code'],
            $request->user(),
            $data['source'] ?? 'qr',
            $request->ip(),
            (bool) ($data['confirm'] ?? false),
        );
        $status = match ($result['state']) {
            'invalid' => 404, 'already_checked_in' => 409, 'wrong_edition', 'void' => 422, default => 200
        };

        return response()->json($result, $status);
    }

    public function checkOut(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalTicket $festivalTicket, FestivalTicketScanner $scanner): JsonResponse
    {
        abort_unless($festivalEdition->account_id === $account->id, 404);
        abort_unless($request->user()?->can('doorStaff', $account), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $result = $scanner->checkOut($festivalEdition, $festivalTicket, $request->user(), $data['reason'], $request->ip());

        return response()->json($result, $result['state'] === 'checked_out' ? 200 : 422);
    }

    private function authorizeScanner(Request $request, Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
        abort_unless(
            $request->user()?->can('checkInFestivalTickets', $account)
                || $request->user()?->can('doorStaff', $account),
            403,
        );
    }

    /** @return array<string, mixed> */
    private function entranceTools(Account $account, FestivalEdition $edition, PaymentGatewayRegistry $gateways): array
    {
        $providers = $gateways->availableSettingsFor($account);
        $ticketTypes = $edition->admissionTypes()
            ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($ticketType): array => [
                'id' => $ticketType->id,
                'name' => $ticketType->name,
                'price_label' => MoneyFormatter::format($ticketType->price_cents, $edition->currency),
                'remaining' => $ticketType->remainingQuantity(),
            ]);

        return [
            'search_url' => route('dashboard.accounts.festivals.entrance.search', [$account, $edition]),
            'cash_sale_url' => route('dashboard.accounts.festivals.entrance.cash', [$account, $edition]),
            'card_sale_url' => route('dashboard.accounts.festivals.entrance.card', [$account, $edition]),
            'ticket_types' => $ticketTypes,
            'payment_providers' => $providers->map(fn (IntegrationSetting $setting): array => [
                'value' => $setting->provider->value,
                'label' => config('integrations.providers.'.$setting->provider->value.'.label', $setting->provider->value),
            ]),
        ];
    }
}
