<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalDoorTicketSale;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Http\Requests\StorePublicEntranceOrderRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicFestivalEntranceController extends Controller
{
    public function show(
        string $accountSlug,
        string $editionSlug,
        PaymentGatewayRegistry $gateways,
    ): View {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $edition = FestivalEdition::query()
            ->whereBelongsTo($account)
            ->where('slug', $editionSlug)
            ->whereIn('status', [FestivalEditionStatus::Published->value, FestivalEditionStatus::InProgress->value])
            ->whereNull('cancelled_at')
            ->where('ends_at', '>', now())
            ->firstOrFail();
        $ticketTypes = $edition->admissionTypes()
            ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('festivals.public.entrance', [
            'account' => $account,
            'edition' => $edition,
            'festivalEdition' => $edition,
            'ticketTypes' => $ticketTypes,
            'paymentSettings' => $gateways->availableSettingsFor($account),
        ]);
    }

    public function store(
        StorePublicEntranceOrderRequest $request,
        string $accountSlug,
        string $editionSlug,
        FestivalDoorTicketSale $sale,
        FestivalPaymentService $payments,
    ): RedirectResponse|View {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $edition = FestivalEdition::query()
            ->whereBelongsTo($account)
            ->where('slug', $editionSlug)
            ->whereIn('status', [FestivalEditionStatus::Published->value, FestivalEditionStatus::InProgress->value])
            ->firstOrFail();
        $order = $sale->execute(
            $account,
            $edition,
            null,
            $request->saleInput(),
            FestivalDoorTicketSale::ModeCard,
            app()->getLocale(),
        );

        if ($order->status !== FestivalTicketOrderStatus::Pending || $order->amount_cents === 0) {
            return redirect()->route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);
        }

        if (filled($order->gateway_checkout_payload)) {
            return redirect()->route('public.festival-orders.payment', [$account->slug, $order->access_token_encrypted]);
        }

        try {
            $checkout = $payments->startOrder($order);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        if ($checkout->isRedirect()) {
            return redirect()->away($checkout->url);
        }

        if ($checkout->isIframe()) {
            return redirect()->route('public.festival-orders.payment', [$account->slug, $order->access_token_encrypted]);
        }

        return view('payments.redirect-form', compact('account', 'checkout'));
    }
}
