<?php

namespace App\Http\Controllers;

use App\Actions\CreateEventOrder;
use App\Actions\StartEventOrderPayment;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Http\Requests\EventGoogleEmailPrefillRequest;
use App\Http\Requests\StoreEventOrderRequest;
use App\Models\Account;
use App\Models\EventOrder;
use App\Support\Events\EventGoogleEmailPrefill;
use App\Support\Events\EventQrCode;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\PaymentGatewayRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
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
        $input = $request->orderInput();
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

    public function google(
        EventGoogleEmailPrefillRequest $request,
        string $accountSlug,
        string $eventSlug,
        EventGoogleEmailPrefill $googleEmailPrefill,
    ): RedirectResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $event = $account->events()
            ->where('slug', $eventSlug)
            ->where('status', EventStatus::Published->value)
            ->firstOrFail();

        return $googleEmailPrefill->redirect($account, $event, $request->checkoutDraft());
    }

    public function googleCallback(Request $request, EventGoogleEmailPrefill $googleEmailPrefill): RedirectResponse
    {
        try {
            $state = $googleEmailPrefill->consumeState($request);
        } catch (ModelNotFoundException|RuntimeException) {
            return redirect()->route('home')->withErrors(['google' => __('app.event_google_prefill_failed')]);
        }

        $checkoutDraft = $state['checkout_draft'];

        try {
            $email = $googleEmailPrefill->verifiedEmail($request);
        } catch (RuntimeException) {
            return redirect()
                ->route('public.events.show', [$state['account']->slug, $state['event']->slug])
                ->withInput($checkoutDraft)
                ->withErrors(['google' => __('app.event_google_prefill_failed')]);
        }

        $checkoutDraft['buyer_email'] = $email;
        $checkoutDraft['buyer_email_confirmation'] = $email;

        return redirect()
            ->route('public.events.show', [$state['account']->slug, $state['event']->slug])
            ->withInput($checkoutDraft);
    }

    public function order(string $accountSlug, string $accessToken): Response
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $order = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->with(['event.location', 'event.rooms', 'items', 'tickets.ticketType'])
            ->firstOrFail();

        $qrCode = app(EventQrCode::class);
        $ticketQrCodes = $this->ticketsAreAvailable($order)
            ? $order->tickets
                ->filter(fn ($ticket): bool => $ticket->status === EventTicketStatus::Valid)
                ->mapWithKeys(fn ($ticket): array => [$ticket->id => $qrCode->dataUri($ticket)])
            : collect();

        $statusUrl = route('public.event-orders.status', [$account->slug, $accessToken]);
        $pdfUrl = route('public.event-orders.pdf', [$account->slug, $accessToken]);

        return response()
            ->view('public.event-order', compact('account', 'order', 'ticketQrCodes', 'statusUrl', 'pdfUrl'))
            ->withHeaders($this->privateHeaders());
    }

    public function status(string $accountSlug, string $accessToken): JsonResponse
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $order = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->with('event:id,status')
            ->withCount([
                'tickets as valid_tickets_count' => fn ($query) => $query->where('status', EventTicketStatus::Valid->value),
            ])
            ->firstOrFail();
        $eventIsPublished = $order->event->status === EventStatus::Published;
        $status = match ($order->status) {
            EventOrderStatus::Pending => ['terminal' => false, 'paid' => false],
            EventOrderStatus::Paid => ['terminal' => true, 'paid' => $eventIsPublished],
            EventOrderStatus::Failed => ['terminal' => true, 'paid' => false],
            EventOrderStatus::Cancelled => ['terminal' => true, 'paid' => false],
            EventOrderStatus::Expired => ['terminal' => true, 'paid' => false],
            EventOrderStatus::PaidRequiresRefund => ['terminal' => true, 'paid' => false],
            EventOrderStatus::RefundRequired => ['terminal' => true, 'paid' => false],
            EventOrderStatus::Refunded => ['terminal' => true, 'paid' => false],
        };

        return response()->json([
            'status' => $order->status->value,
            'terminal' => $status['terminal'],
            'paid' => $status['paid'],
            'tickets_ready' => $status['paid'] && (int) $order->valid_tickets_count > 0,
            'event_cancelled' => $order->event->status === EventStatus::Cancelled,
        ])->withHeaders($this->privateHeaders());
    }

    public function pdf(
        string $accountSlug,
        string $accessToken,
        EventQrCode $qrCode,
    ): Response {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $order = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->where('status', EventOrderStatus::Paid->value)
            ->whereHas('event', fn ($query) => $query->where('status', EventStatus::Published->value))
            ->with([
                'event.location',
                'event.rooms',
                'tickets' => fn ($query) => $query
                    ->where('status', EventTicketStatus::Valid->value)
                    ->with('ticketType'),
            ])
            ->firstOrFail();

        abort_if($order->tickets->isEmpty(), 404);

        $venue = $order->event->venue_kind->value === 'studio'
            ? collect([
                $order->event->location?->name,
                $order->event->location?->address,
                $order->event->rooms->pluck('name')->join(', '),
            ])->filter()->join(' · ')
            : collect([$order->event->external_venue_name, $order->event->external_address])->filter()->join(' · ');
        $ticketQrCodes = $order->tickets
            ->mapWithKeys(fn ($ticket): array => [$ticket->id => $qrCode->dataUri($ticket)]);
        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ], true)
            ->setPaper('a4', 'portrait')
            ->loadView('public.event-tickets-pdf', compact('account', 'order', 'ticketQrCodes', 'venue'));

        return $pdf
            ->download('event-tickets-'.$order->order_id.'.pdf')
            ->withHeaders($this->privateHeaders());
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
            ->where('status', EventOrderStatus::Paid->value)
            ->whereHas('event', fn ($query) => $query->where('status', EventStatus::Published->value))
            ->firstOrFail();
        $ticket = $order->tickets()
            ->where('code', $ticketCode)
            ->where('status', EventTicketStatus::Valid->value)
            ->firstOrFail();

        return response($qrCode->png($ticket), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$ticket->code.'.png"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ticketsAreAvailable(EventOrder $order): bool
    {
        return $order->status === EventOrderStatus::Paid
            && $order->event->status === EventStatus::Published;
    }

    /**
     * @return array<string, string>
     */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
