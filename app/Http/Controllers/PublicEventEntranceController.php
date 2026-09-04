<?php

namespace App\Http\Controllers;

use App\Actions\EventDoorTicketSale;
use App\Actions\StartEventOrderPayment;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Http\Requests\StorePublicEntranceOrderRequest;
use App\Models\Account;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicEventEntranceController extends Controller
{
    public function show(
        string $accountSlug,
        string $eventSlug,
        PaymentGatewayRegistry $gateways,
    ): View {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $event = $account->events()
            ->where('slug', $eventSlug)
            ->where('status', EventStatus::Published->value)
            ->where('ends_at', '>', now())
            ->firstOrFail();
        $ticketTypes = $event->ticketTypes()
            ->where('is_active', true)
            ->withSoldOrHeldQuantity()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('public.event-entrance', [
            'account' => $account,
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'paymentSettings' => $gateways->availableSettingsFor($account),
        ]);
    }

    public function store(
        StorePublicEntranceOrderRequest $request,
        string $accountSlug,
        string $eventSlug,
        EventDoorTicketSale $sale,
        StartEventOrderPayment $startPayment,
        PaymentGatewayRegistry $gateways,
    ): RedirectResponse|View {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $event = $account->events()
            ->where('slug', $eventSlug)
            ->where('status', EventStatus::Published->value)
            ->firstOrFail();
        $input = $request->saleInput();
        $order = $sale->execute(
            $account,
            $event,
            null,
            $input,
            EventDoorTicketSale::ModeCard,
            app()->getLocale(),
        );

        if ($order->status !== EventOrderStatus::Pending || $order->amount_cents === 0) {
            return redirect()->route('public.event-orders.show', [$account->slug, $order->access_token_encrypted]);
        }

        if (filled($order->gateway_checkout_payload)) {
            return redirect()->route('public.event-orders.payment', [$account->slug, $order->access_token_encrypted]);
        }

        $setting = $gateways->availableSettingsFor($account)
            ->first(fn ($setting): bool => $setting->provider->value === $order->provider);
        abort_unless($setting, 422, __('app.payment_provider_unavailable'));
        try {
            $checkout = $startPayment->execute($order, $setting, $request->userAgent());
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        if ($checkout->isRedirect()) {
            return redirect()->away($checkout->url);
        }

        if ($checkout->isIframe()) {
            return redirect()->route('public.event-orders.payment', [$account->slug, $order->access_token_encrypted]);
        }

        return view('payments.redirect-form', compact('account', 'checkout'));
    }
}
