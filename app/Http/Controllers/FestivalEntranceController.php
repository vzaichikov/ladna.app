<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalDoorTicketSale;
use App\Actions\Festivals\FestivalTicketScanner;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Http\Requests\EntranceGuestSearchRequest;
use App\Http\Requests\StoreDoorTicketSaleRequest;
use App\Http\Requests\UndoTicketAdmissionRequest;
use App\Models\Account;
use App\Models\FestivalCashEntry;
use App\Models\FestivalEdition;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Entrance\EntrancePresenter;
use App\Support\Entrance\EntranceQrCode;
use App\Support\EventFestivalStaffAccess;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\MoneyFormatter;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FestivalEntranceController extends Controller
{
    public function __construct(
        private readonly EntrancePresenter $presenter,
        private readonly EventFestivalStaffAccess $staffAccess,
    ) {}

    public function attendance(
        Request $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalWorkspaceAccess $workspaceAccess,
        PaymentGatewayRegistry $gateways,
    ): View {
        $this->authorizeDoorStaff($request, $account, $festivalEdition);

        return view('festivals.staff.attendance', [
            'account' => $account,
            'edition' => $festivalEdition,
            'festivalEdition' => $festivalEdition,
            'overview' => $this->overview($festivalEdition),
            'workspacePermissions' => $workspaceAccess->permissions($request->user(), $account, $festivalEdition),
            'entranceTools' => $this->entranceTools($account, $festivalEdition, $gateways),
        ]);
    }

    public function attendanceData(Request $request, Account $account, FestivalEdition $festivalEdition): JsonResponse
    {
        $this->authorizeDoorStaff($request, $account, $festivalEdition);

        return response()->json($this->overview($festivalEdition))
            ->withHeaders($this->privateHeaders());
    }

    public function undo(
        UndoTicketAdmissionRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalTicket $festivalTicket,
        FestivalTicketScanner $scanner,
    ): JsonResponse {
        $this->assertEditionScope($account, $festivalEdition);
        $result = $scanner->checkOut(
            $festivalEdition,
            $festivalTicket,
            $request->user(),
            $request->validated('reason'),
            $request->ip(),
        );

        return response()->json($result, $result['state'] === 'checked_out' ? 200 : 422)
            ->withHeaders($this->privateHeaders());
    }

    public function search(EntranceGuestSearchRequest $request, Account $account, FestivalEdition $festivalEdition): JsonResponse
    {
        $this->assertEditionScope($account, $festivalEdition);
        $search = $request->validated('q');
        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $search) ?: '';
        $phoneLike = strlen($digits) >= 4 ? '%'.$digits.'%' : null;

        $orders = FestivalTicketOrder::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($festivalEdition, 'edition')
            ->whereHas('tickets')
            ->where(function ($query) use ($like, $phoneLike): void {
                $query
                    ->where('buyer_name', 'like', $like)
                    ->orWhere('buyer_email', 'like', $like)
                    ->orWhere('buyer_phone', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhereHas('tickets', fn ($tickets) => $tickets
                        ->where('code', 'like', $like)
                        ->orWhere('holder_name', 'like', $like))
                    ->when($phoneLike, fn ($query, string $value) => $query->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(buyer_phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '') LIKE ?",
                        [$value],
                    ));
            })
            ->with(['tickets.admissionType'])
            ->latest('id')
            ->limit(12)
            ->get();

        return response()->json([
            'results' => $orders->map(fn (FestivalTicketOrder $order): array => $this->orderResult($order))->all(),
        ])->withHeaders($this->privateHeaders());
    }

    public function cashSale(
        StoreDoorTicketSaleRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalDoorTicketSale $sale,
    ): JsonResponse {
        $this->assertEditionScope($account, $festivalEdition);
        $order = $sale->execute(
            $account,
            $festivalEdition,
            $request->user(),
            $request->saleInput(),
            FestivalDoorTicketSale::ModeCash,
            app()->getLocale(),
        );

        return response()->json($this->saleResult($account, $order), 201)
            ->withHeaders($this->privateHeaders());
    }

    public function cardSale(
        StoreDoorTicketSaleRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalDoorTicketSale $sale,
        FestivalPaymentService $payments,
        EntranceQrCode $qrCode,
    ): JsonResponse {
        $this->assertEditionScope($account, $festivalEdition);
        $input = $request->saleInput();

        if (blank($input['provider'] ?? null)) {
            throw ValidationException::withMessages(['provider' => __('app.payment_provider_required')]);
        }

        $order = $sale->execute(
            $account,
            $festivalEdition,
            $request->user(),
            $input,
            FestivalDoorTicketSale::ModeCard,
            app()->getLocale(),
        );

        if ($order->status === FestivalTicketOrderStatus::Pending && blank($order->gateway_checkout_payload)) {
            try {
                $payments->startOrder($order);
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
            }
        }

        if ($order->status !== FestivalTicketOrderStatus::Pending) {
            return response()->json($this->saleResult($account, $order->refresh()), 201)
                ->withHeaders($this->privateHeaders());
        }

        $paymentUrl = route('public.festival-orders.payment', [$account->slug, $order->access_token_encrypted]);

        return response()->json([
            ...$this->saleResult($account, $order->refresh()),
            'payment' => [
                'url' => $paymentUrl,
                'status_url' => route('public.festival-orders.status', [$account->slug, $order->access_token_encrypted]),
                'qr_data_uri' => $qrCode->dataUri($paymentUrl),
            ],
        ], 201)->withHeaders($this->privateHeaders());
    }

    public function poster(
        Account $account,
        FestivalEdition $festivalEdition,
        PaymentGatewayRegistry $gateways,
        EntranceQrCode $qrCode,
    ): View {
        $this->assertEditionScope($account, $festivalEdition);
        $user = request()->user();
        abort_unless(
            request()->user()?->can('doorStaff', $account)
                || ($user instanceof User && $this->staffAccess->canAccessFestival($user, $account, $festivalEdition)),
            403,
        );
        $paymentSettings = $gateways->availableSettingsFor($account);
        abort_if($paymentSettings->isEmpty(), 422, __('app.no_payment_methods_available'));

        $url = route('public.festivals.entrance', [$account->slug, $festivalEdition->slug]);

        return view('festivals.staff.entrance-poster', [
            'account' => $account,
            'edition' => $festivalEdition,
            'festivalEdition' => $festivalEdition,
            'url' => $url,
            'qrCode' => $qrCode->dataUri($url),
            'paymentSettings' => $paymentSettings,
        ]);
    }

    /** @return array<string, mixed> */
    private function saleResult(Account $account, FestivalTicketOrder $order): array
    {
        $order->loadMissing(['tickets.admissionType']);

        return [
            'order' => [
                'id' => $order->id,
                'reference' => $order->order_id,
                'status' => $order->status->value,
                'amount_cents' => $order->amount_cents,
                'currency' => $order->currency,
                'url' => route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]),
            ],
            'tickets' => $order->tickets->map(fn (FestivalTicket $ticket): array => $this->ticketResult($ticket, $order))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function orderResult(FestivalTicketOrder $order): array
    {
        return [
            'order_id' => $order->id,
            'reference' => $order->order_id,
            'guest' => [
                'name' => $order->buyer_name,
                'email' => $this->presenter->email(filled($order->buyer_email) ? $order->buyer_email : null),
                'phone' => $this->presenter->phone($order->buyer_phone),
            ],
            'tickets' => $order->tickets
                ->map(fn (FestivalTicket $ticket): array => $this->ticketResult($ticket, $order))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function ticketResult(FestivalTicket $ticket, FestivalTicketOrder $order): array
    {
        return [
            'id' => $ticket->id,
            'code' => $ticket->code,
            'type' => $ticket->admissionType?->name,
            'holder_name' => $ticket->holder_name ?: $order->buyer_name,
            'status' => $ticket->status->value,
            'passed' => $ticket->is_checked_in,
            'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
            'can_admit' => $ticket->status === FestivalTicketStatus::Valid
                && $order->status === FestivalTicketOrderStatus::Paid
                && ! $ticket->is_checked_in,
        ];
    }

    private function assertEditionScope(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
        $user = request()->user();

        if ($user instanceof User && $this->staffAccess->isStaff($user, $account)) {
            abort_unless($this->staffAccess->canAccessFestival($user, $account, $edition), 403);
        }
    }

    private function authorizeDoorStaff(Request $request, Account $account, FestivalEdition $edition): void
    {
        $this->assertEditionScope($account, $edition);
        $user = $request->user();
        abort_unless(
            $request->user()?->can('doorStaff', $account)
                || ($user instanceof User && $this->staffAccess->canAccessFestival($user, $account, $edition)),
            403,
        );
    }

    /** @return array<string, mixed> */
    private function overview(FestivalEdition $edition): array
    {
        $tickets = FestivalTicket::query()
            ->where('festival_edition_id', $edition->id)
            ->where('status', FestivalTicketStatus::Valid->value)
            ->whereHas('order', fn ($query) => $query->where('status', FestivalTicketOrderStatus::Paid->value))
            ->with(['order:id,buyer_name,status', 'admissionType:id,name'])
            ->orderByDesc('is_checked_in')
            ->orderByDesc('checked_in_at')
            ->orderBy('code')
            ->get();
        $cashBalance = (int) $edition->cashEntries()
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount_cents ELSE -amount_cents END), 0) as balance', [FestivalCashEntry::DirectionIn])
            ->value('balance');
        $cashHistory = $edition->cashEntries()
            ->with('order:id,buyer_name')
            ->latest('occurred_at')
            ->latest('id')
            ->limit(20)
            ->get();
        $cashHistoryResult = $cashHistory->map(fn (FestivalCashEntry $entry): array => [
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
            'occurred_at_label' => $entry->occurred_at?->timezone($edition->timezone)->format('d.m.Y H:i'),
        ])->all();
        $cashBalanceResult = [
            'amount_cents' => $cashBalance,
            'currency' => strtoupper($edition->currency),
            'label' => MoneyFormatter::format($cashBalance, $edition->currency),
        ];

        return [
            'total' => $tickets->count(),
            'passed' => $tickets->where('is_checked_in', true)->count(),
            'unpassed' => $tickets->where('is_checked_in', false)->count(),
            'waiting' => $tickets->where('is_checked_in', false)->count(),
            'updated_at_label' => now($edition->timezone)->format('H:i:s'),
            'cash_balances' => [$cashBalanceResult],
            'cash_history' => $cashHistoryResult,
            'cash' => [
                ...$cashBalanceResult,
                'formatted' => $cashBalanceResult['label'],
                'history' => $cashHistoryResult,
            ],
            'tickets' => $tickets->map(fn (FestivalTicket $ticket): array => [
                'id' => $ticket->id,
                'customer_name' => $ticket->holder_name ?: ($ticket->order?->buyer_name ?? __('app.unknown')),
                'code' => $ticket->code,
                'type' => $ticket->admissionType?->name,
                'passed' => $ticket->is_checked_in,
                'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
                'checked_in_at_label' => $ticket->checked_in_at?->timezone($edition->timezone)->format('d.m.Y H:i'),
                'undo_url' => route('dashboard.accounts.festivals.attendance.tickets.undo', [$edition->account_id, $edition, $ticket]),
            ])->all(),
        ];
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

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
