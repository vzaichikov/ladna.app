<?php

namespace App\Http\Controllers;

use App\Actions\EventDoorTicketSale;
use App\Actions\StartEventOrderPayment;
use App\Enums\EventOrderStatus;
use App\Enums\EventTicketStatus;
use App\Http\Requests\EntranceGuestSearchRequest;
use App\Http\Requests\StoreDoorTicketSaleRequest;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventTicket;
use App\Models\User;
use App\Support\Entrance\EntrancePresenter;
use App\Support\Entrance\EntranceQrCode;
use App\Support\EventFestivalStaffAccess;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class EventEntranceController extends Controller
{
    public function __construct(
        private readonly EntrancePresenter $presenter,
        private readonly EventFestivalStaffAccess $staffAccess,
    ) {}

    public function search(EntranceGuestSearchRequest $request, Account $account, Event $event): JsonResponse
    {
        $this->assertEventScope($account, $event);
        $search = $request->validated('q');
        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $search) ?: '';
        $phoneLike = strlen($digits) >= 4 ? '%'.$digits.'%' : null;

        $orders = EventOrder::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($event)
            ->whereHas('tickets')
            ->where(function ($query) use ($like, $phoneLike): void {
                $query
                    ->where('buyer_name', 'like', $like)
                    ->orWhere('buyer_email', 'like', $like)
                    ->orWhere('buyer_phone', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhereHas('tickets', fn ($tickets) => $tickets->where('code', 'like', $like))
                    ->when($phoneLike, fn ($query, string $value) => $query->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(buyer_phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '') LIKE ?",
                        [$value],
                    ));
            })
            ->with(['tickets.ticketType'])
            ->latest('id')
            ->limit(12)
            ->get();

        return response()->json([
            'results' => $orders->map(fn (EventOrder $order): array => $this->orderResult($order))->all(),
        ])->withHeaders($this->privateHeaders());
    }

    public function cashSale(
        StoreDoorTicketSaleRequest $request,
        Account $account,
        Event $event,
        EventDoorTicketSale $sale,
    ): JsonResponse {
        $this->assertEventScope($account, $event);
        $order = $sale->execute(
            $account,
            $event,
            $request->user(),
            $request->saleInput(),
            EventDoorTicketSale::ModeCash,
            app()->getLocale(),
        );

        return response()->json($this->saleResult($account, $order), 201)
            ->withHeaders($this->privateHeaders());
    }

    public function cardSale(
        StoreDoorTicketSaleRequest $request,
        Account $account,
        Event $event,
        EventDoorTicketSale $sale,
        StartEventOrderPayment $startPayment,
        PaymentGatewayRegistry $gateways,
        EntranceQrCode $qrCode,
    ): JsonResponse {
        $this->assertEventScope($account, $event);
        $input = $request->saleInput();

        if (blank($input['provider'] ?? null)) {
            throw ValidationException::withMessages(['provider' => __('app.payment_provider_required')]);
        }

        $order = $sale->execute(
            $account,
            $event,
            $request->user(),
            $input,
            EventDoorTicketSale::ModeCard,
            app()->getLocale(),
        );

        if ($order->status === EventOrderStatus::Pending && blank($order->gateway_checkout_payload)) {
            $setting = $gateways->availableSettingsFor($account)
                ->first(fn ($setting): bool => $setting->provider->value === $order->provider);

            if (! $setting) {
                throw ValidationException::withMessages(['provider' => __('app.payment_provider_unavailable')]);
            }

            try {
                $startPayment->execute($order, $setting, $request->userAgent());
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
            }
        }

        if ($order->status !== EventOrderStatus::Pending) {
            return response()->json($this->saleResult($account, $order->refresh()), 201)
                ->withHeaders($this->privateHeaders());
        }

        $paymentUrl = route('public.event-orders.payment', [$account->slug, $order->access_token_encrypted]);

        return response()->json([
            ...$this->saleResult($account, $order->refresh()),
            'payment' => [
                'url' => $paymentUrl,
                'status_url' => route('public.event-orders.status', [$account->slug, $order->access_token_encrypted]),
                'qr_data_uri' => $qrCode->dataUri($paymentUrl),
            ],
        ], 201)->withHeaders($this->privateHeaders());
    }

    public function poster(
        Account $account,
        Event $event,
        PaymentGatewayRegistry $gateways,
        EntranceQrCode $qrCode,
    ): View {
        $this->assertEventScope($account, $event);
        $user = request()->user();
        abort_unless(
            request()->user()?->can('doorStaff', $account)
                || ($user instanceof User && $this->staffAccess->canAccessEvent($user, $account, $event)),
            403,
        );
        $paymentSettings = $gateways->availableSettingsFor($account);
        abort_if($paymentSettings->isEmpty(), 422, __('app.no_payment_methods_available'));

        $url = route('public.events.entrance', [$account->slug, $event->slug]);

        return view('events.entrance-poster', [
            'account' => $account,
            'event' => $event,
            'url' => $url,
            'qrCode' => $qrCode->dataUri($url),
            'paymentSettings' => $paymentSettings,
        ]);
    }

    /** @return array<string, mixed> */
    private function saleResult(Account $account, EventOrder $order): array
    {
        $order->loadMissing(['tickets.ticketType']);

        return [
            'order' => [
                'id' => $order->id,
                'reference' => $order->order_id,
                'status' => $order->status->value,
                'amount_cents' => $order->amount_cents,
                'currency' => $order->currency,
                'url' => route('public.event-orders.show', [$account->slug, $order->access_token_encrypted]),
            ],
            'tickets' => $order->tickets->map(fn (EventTicket $ticket): array => $this->ticketResult($ticket, $order))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function orderResult(EventOrder $order): array
    {
        return [
            'order_id' => $order->id,
            'reference' => $order->order_id,
            'guest' => [
                'name' => $order->buyer_name,
                'email' => $this->presenter->email($order->buyer_email),
                'phone' => $this->presenter->phone($order->buyer_phone),
            ],
            'tickets' => $order->tickets
                ->map(fn (EventTicket $ticket): array => $this->ticketResult($ticket, $order))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function ticketResult(EventTicket $ticket, EventOrder $order): array
    {
        return [
            'id' => $ticket->id,
            'code' => $ticket->code,
            'type' => $ticket->ticketType?->name,
            'status' => $ticket->status->value,
            'passed' => $ticket->is_checked_in,
            'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
            'can_admit' => $ticket->status === EventTicketStatus::Valid
                && $order->status === EventOrderStatus::Paid
                && ! $ticket->is_checked_in,
        ];
    }

    private function assertEventScope(Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
        $user = request()->user();

        if ($user instanceof User && $this->staffAccess->isStaff($user, $account)) {
            abort_unless($this->staffAccess->canAccessEvent($user, $account, $event), 403);
        }
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
