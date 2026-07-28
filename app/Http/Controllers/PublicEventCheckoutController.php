<?php

namespace App\Http\Controllers;

use App\Actions\CreateEventOrder;
use App\Actions\StartEventOrderPayment;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Http\Requests\StoreEventOrderRequest;
use App\Models\Account;
use App\Models\EventOrder;
use App\Support\Events\EventQrCode;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicEventCheckoutController extends Controller
{
    public function store(
        StoreEventOrderRequest $request,
        string $accountSlug,
        string $eventSlug,
        CreateEventOrder $createOrder,
        StartEventOrderPayment $startPayment,
        PaymentGatewayRegistry $gateways,
        TransactionalMailDispatcher $mailDispatcher,
    ): RedirectResponse|View {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $event = $account->events()->where('slug', $eventSlug)->where('status', EventStatus::Published->value)->firstOrFail();
        $input = $request->validated();
        $paymentSettings = $gateways->availableSettingsFor($account);

        if (filled($input['provider'] ?? null)
            && ! $paymentSettings->contains(fn ($setting): bool => $setting->provider->value === $input['provider'])) {
            throw ValidationException::withMessages(['provider' => __('app.payment_provider_unavailable')]);
        }

        $order = $createOrder->execute($event, $input, app()->getLocale());

        if ($order->amount_cents === 0) {
            $mailDispatcher->eventTicketsIssued($order);

            return redirect()->route('public.event-orders.show', [$account->slug, $order->access_token_encrypted]);
        }

        $setting = $paymentSettings
            ->first(fn ($setting): bool => $setting->provider->value === $input['provider']);

        if (! $setting) {
            throw ValidationException::withMessages(['provider' => __('app.payment_provider_unavailable')]);
        }

        try {
            $checkout = $startPayment->execute($order, $setting);
        } catch (Throwable $exception) {
            report($exception);
            $order->forceFill([
                'status' => EventOrderStatus::Failed,
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();
            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        if ($checkout->isRedirect()) {
            return redirect()->away($checkout->url);
        }

        return view('payments.redirect-form', compact('account', 'checkout'));
    }

    public function order(Request $request, string $accountSlug, string $accessToken): View
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $order = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->with(['event.location', 'event.rooms', 'items', 'tickets.ticketType'])
            ->firstOrFail();

        $qrCode = app(EventQrCode::class);
        $ticketQrCodes = $order->tickets
            ->filter(fn ($ticket): bool => $ticket->status === EventTicketStatus::Valid && $order->event->status !== EventStatus::Cancelled)
            ->mapWithKeys(fn ($ticket): array => [$ticket->id => $qrCode->dataUri($ticket)]);

        return view('public.event-order', compact('account', 'order', 'ticketQrCodes'));
    }

    public function ticketQr(
        string $accountSlug,
        string $accessToken,
        string $ticketCode,
        EventQrCode $qrCode,
    ): Response {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $order = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->whereHas('event', fn ($query) => $query->where('status', '!=', EventStatus::Cancelled->value))
            ->firstOrFail();
        $ticket = $order->tickets()
            ->where('code', $ticketCode)
            ->where('status', EventTicketStatus::Valid->value)
            ->firstOrFail();

        return response($qrCode->png($ticket), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$ticket->code.'.png"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
