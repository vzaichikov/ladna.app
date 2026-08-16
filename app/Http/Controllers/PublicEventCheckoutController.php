<?php

namespace App\Http\Controllers;

use App\Actions\CreateEventOrder;
use App\Actions\StartEventOrderPayment;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Enums\IntegrationProvider;
use App\Http\Requests\EventGoogleEmailPrefillRequest;
use App\Http\Requests\StoreEventOrderRequest;
use App\Models\Account;
use App\Models\EventOrder;
use App\Support\Events\EventGoogleEmailPrefill;
use App\Support\Events\EventQrCode;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\MonopayGateway;
use App\Support\Payments\PaymentCheckout;
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
            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        if ($checkout->isRedirect()) {
            return redirect()->away($checkout->url);
        }

        if ($checkout->isIframe()) {
            return redirect()->route('public.event-orders.payment', [
                $account->slug,
                $order->access_token_encrypted,
            ]);
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
            $profile = $googleEmailPrefill->verifiedProfile($request);
        } catch (RuntimeException) {
            return redirect()
                ->route('public.events.show', [$state['account']->slug, $state['event']->slug])
                ->withInput($checkoutDraft)
                ->withErrors(['google' => __('app.event_google_prefill_failed')]);
        }

        if (blank($checkoutDraft['buyer_name'] ?? null) && filled($profile['name'])) {
            $checkoutDraft['buyer_name'] = $profile['name'];
        }

        $checkoutDraft['buyer_email'] = $profile['email'];
        $checkoutDraft['buyer_email_confirmation'] = $profile['email'];

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
        $paymentUrl = $this->paymentIsAvailable($order)
            && $this->paymentLauncherData($order) !== null
                ? route('public.event-orders.payment', [$account->slug, $accessToken])
                : null;

        return response()
            ->view('public.event-order', compact('account', 'order', 'ticketQrCodes', 'statusUrl', 'pdfUrl', 'paymentUrl'))
            ->withHeaders($this->privateHeaders());
    }

    public function payment(string $accountSlug, string $accessToken): RedirectResponse|Response
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $order = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->with('event')
            ->firstOrFail();
        $returnUrl = route('public.event-orders.show', [$account->slug, $accessToken]);

        if (! $this->paymentIsAvailable($order)) {
            return redirect()->to($returnUrl);
        }

        $launcher = $this->paymentLauncherData($order);
        abort_if($launcher === null, 404);

        if ($launcher['type'] === 'redirect') {
            return redirect()->away($launcher['url']);
        }

        if ($launcher['type'] === 'form') {
            $checkout = PaymentCheckout::form($launcher['url'], $launcher['fields'], method: $launcher['method']);

            return response()
                ->view('payments.redirect-form', compact('account', 'checkout'))
                ->withHeaders($this->privateHeaders());
        }

        $iframeCheckout = $this->iframeCheckoutData($order);
        abort_if($iframeCheckout === null, 404);

        $statusUrl = route('public.event-orders.status', [$account->slug, $accessToken]);

        return response()
            ->view('public.event-order-payment', [
                'account' => $account,
                'order' => $order,
                'pageUrl' => $iframeCheckout['page_url'],
                'iframeOrigin' => $iframeCheckout['origin'],
                'returnUrl' => $returnUrl,
                'statusUrl' => $statusUrl,
            ])
            ->withHeaders([
                ...$this->privateHeaders(),
                'Content-Security-Policy' => "frame-src {$iframeCheckout['origin']}; frame-ancestors 'self'",
                'Permissions-Policy' => "payment=(self \"{$iframeCheckout['origin']}\")",
            ]);
    }

    public function status(string $accountSlug, string $accessToken): JsonResponse
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $order = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->with('event:id,status')
            ->with(['tickets' => fn ($query) => $query
                ->where('status', EventTicketStatus::Valid->value)
                ->with('ticketType:id,name')
                ->orderBy('id')
                ->limit(1)])
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
            'ticket' => $status['paid'] && $order->tickets->isNotEmpty() ? [
                'code' => $order->tickets->first()->code,
                'type' => $order->tickets->first()->ticketType?->name,
                'customer' => $order->buyer_name,
            ] : null,
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

    private function paymentIsAvailable(EventOrder $order): bool
    {
        $paymentDeadline = $order->payment_expires_at ?? $order->expires_at;

        return $order->status === EventOrderStatus::Pending
            && $order->event->status === EventStatus::Published
            && $order->event->starts_at->isFuture()
            && ($paymentDeadline === null || $paymentDeadline->isFuture());
    }

    /**
     * @return array{page_url: string, origin: string}|null
     */
    private function iframeCheckoutData(EventOrder $order): ?array
    {
        if ($order->provider !== IntegrationProvider::Monopay->value
            || data_get($order->gateway_checkout_payload, 'request.displayType') !== 'iframe') {
            return null;
        }

        $pageUrl = data_get($order->gateway_checkout_payload, 'response.pageUrl');

        if (! is_string($pageUrl)) {
            return null;
        }

        $origin = MonopayGateway::trustedIframeOrigin($pageUrl);

        return $origin ? ['page_url' => $pageUrl, 'origin' => $origin] : null;
    }

    /** @return array{type: string, url: string, method: string, fields: array<string, mixed>}|null */
    private function paymentLauncherData(EventOrder $order): ?array
    {
        $launcher = data_get($order->gateway_checkout_payload, '_launcher');

        if (! is_array($launcher)
            || ! in_array($launcher['type'] ?? null, ['redirect', 'form', 'iframe'], true)
            || ! is_string($launcher['url'] ?? null)
            || ! str_starts_with($launcher['url'], 'https://')) {
            $iframe = $this->iframeCheckoutData($order);

            return $iframe ? ['type' => 'iframe', 'url' => $iframe['page_url'], 'method' => 'GET', 'fields' => []] : null;
        }

        return [
            'type' => $launcher['type'],
            'url' => $launcher['url'],
            'method' => in_array(strtoupper((string) ($launcher['method'] ?? 'GET')), ['GET', 'POST'], true)
                ? strtoupper((string) ($launcher['method'] ?? 'GET'))
                : 'POST',
            'fields' => is_array($launcher['fields'] ?? null) ? $launcher['fields'] : [],
        ];
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
