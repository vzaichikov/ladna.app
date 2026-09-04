<?php

namespace App\Http\Controllers;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTimeline;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\Festivals\FestivalLandingRegistry;
use App\Support\Festivals\FestivalQrToken;
use App\Support\Festivals\FestivalTimelinePresenter;
use App\Support\Payments\MonopayGateway;
use App\Support\Payments\MonopayIframeCompatibility;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentGatewayRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FestivalPublicController extends Controller
{
    public function index(Request $request, string $accountSlug): View
    {
        $account = $this->account($request, $accountSlug);
        $editions = FestivalEdition::query()->whereBelongsTo($account)->published()->with('series')->orderBy('starts_at')->paginate(24);

        return view('festivals.public.index', compact('account', 'editions'));
    }

    public function show(
        Request $request,
        string $accountSlug,
        string $editionSlug,
        FestivalLandingRegistry $landingRegistry,
        FestivalTimelinePresenter $timelinePresenter,
        PaymentGatewayRegistry $gateways,
        CustomerAuthAvailability $authAvailability,
    ): View {
        $account = $this->account($request, $accountSlug);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)
            ->with(['series.telegramBotInstallation', 'sections' => fn ($query) => $query->where('visibility', 'public')->where('is_active', true), 'media' => fn ($query) => $query->where('is_active', true), 'admissionTypes' => fn ($query) => $query->availableForSale()->with('onlineStream'), 'results' => fn ($query) => $query->whereNotNull('published_at'), 'results.entry.category'])
            ->firstOrFail();
        $landingTemplateKey = $landingRegistry->effectiveTemplateKey($edition, $account);
        $landingPaletteKey = $landingRegistry->effectivePaletteKey($edition);
        $landingTemplate = $landingRegistry->template($landingTemplateKey);
        $timelineWithinDates = $timelinePresenter->isWithinLocalDates($edition);
        $publicTimelines = $timelineWithinDates
            ? FestivalTimeline::query()
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $account->id)
                ->whereNotNull('started_at')
                ->whereHas('stage', fn ($query) => $query->where('is_active', true))
                ->with(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem'])
                ->get()
            : collect();
        $publicTimelineViews = $timelinePresenter->scenes($publicTimelines, true);
        $timelinePollingUrl = route('public.festivals.timeline', [$account->slug, $edition->slug]);
        $festivalAdmissionOptions = $edition->admissionTypes->map(function ($type): array {
            $price = $type->currentPrice();
            $remainingQuantity = $type->remainingQuantity();
            $maxQuantity = min($type->max_per_order, $remainingQuantity);
            $earlyBirdAvailable = $price['tier'] === 'early_bird';
            $earlyBirdMaxQuantity = $maxQuantity;

            if ($earlyBirdAvailable && $type->early_bird_quota !== null) {
                $earlyBirdSoldOrHeld = (int) $type->orderItems()
                    ->where('price_tier', 'early_bird')
                    ->whereHas('order', fn ($query) => $query
                        ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value])
                        ->where(fn ($query) => $query
                            ->where('status', '!=', FestivalTicketOrderStatus::Pending->value)
                            ->orWhere('expires_at', '>', now())))
                    ->sum('quantity');
                $earlyBirdMaxQuantity = min($maxQuantity, max(0, $type->early_bird_quota - $earlyBirdSoldOrHeld));
            }

            return [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'price_cents' => $price['price_cents'],
                'regular_price_cents' => $type->price_cents,
                'early_bird_price_cents' => $earlyBirdAvailable ? $type->early_bird_price_cents : null,
                'early_bird_max_quantity' => $earlyBirdAvailable ? $earlyBirdMaxQuantity : 0,
                'early_bird_available' => $earlyBirdAvailable,
                'remaining_quantity' => $remainingQuantity,
                'max_quantity' => $maxQuantity,
                'sales_open' => $type->saleIsOpen(),
                'exclusive' => $type->delivery_mode === FestivalAdmissionDeliveryMode::OnlineStream,
            ];
        });
        $festivalPaymentSettings = $gateways->availableSettingsFor($account);
        $festivalGoogleEmailPrefillAvailable = $authAvailability->googleSetting() !== null;
        $telegramInstallation = $edition->series->telegramBotInstallation;
        $festivalTelegramBotUrl = $telegramInstallation?->is_enabled && filled($telegramInstallation->bot_username)
            ? 'https://t.me/'.ltrim((string) $telegramInstallation->bot_username, '@')
            : null;
        $authenticatedPortalUser = $request->user('festival');
        $festivalFriendPurchase = $request->boolean('friends')
            && $authenticatedPortalUser instanceof FestivalPortalUser
            && $authenticatedPortalUser->account_id === $account->id
            && $authenticatedPortalUser->role === FestivalPortalRole::Registrant
            && $authenticatedPortalUser->is_active;
        $festivalCheckoutPrefill = $festivalFriendPurchase ? [
            'buyer_name' => $authenticatedPortalUser->displayName(),
            'buyer_email' => $authenticatedPortalUser->email,
            'buyer_email_confirmation' => $authenticatedPortalUser->email,
            'buyer_phone' => $authenticatedPortalUser->phone,
        ] : [];

        return view($landingTemplate['view'], compact(
            'account',
            'edition',
            'landingTemplateKey',
            'landingPaletteKey',
            'publicTimelineViews',
            'timelineWithinDates',
            'timelinePollingUrl',
            'festivalAdmissionOptions',
            'festivalPaymentSettings',
            'festivalGoogleEmailPrefillAvailable',
            'festivalTelegramBotUrl',
            'festivalFriendPurchase',
            'festivalCheckoutPrefill',
        ));
    }

    public function order(Request $request, string $accountSlug, string $accessToken, FestivalQrToken $qr): Response
    {
        $account = $this->account($request, $accountSlug);
        $order = $this->ticketOrder($account, $accessToken, [
            'edition',
            'items',
            'tickets.admissionType',
            'tickets.orderItem',
            'tickets.streamEntitlement.stream',
        ]);
        $qrCodes = $this->ticketsAreAvailable($order)
            ? $order->tickets
                ->filter(fn ($ticket): bool => $ticket->status === FestivalTicketStatus::Valid
                    && $ticket->admissionType?->delivery_mode === FestivalAdmissionDeliveryMode::Venue)
                ->mapWithKeys(fn ($ticket): array => [$ticket->id => $qr->dataUri($ticket)])
            : collect();
        $statusUrl = route('public.festival-orders.status', [$account->slug, $accessToken]);
        $pdfUrl = route('public.festival-orders.pdf', [$account->slug, $accessToken]);
        $paymentUrl = $this->paymentIsAvailable($order) && $this->paymentLauncherData($order) !== null
            ? route('public.festival-orders.payment', [$account->slug, $accessToken])
            : null;

        return response()
            ->view('festivals.public.order', compact('account', 'order', 'qrCodes', 'statusUrl', 'pdfUrl', 'paymentUrl'))
            ->withHeaders($this->privateHeaders());
    }

    public function orderPayment(
        Request $request,
        string $accountSlug,
        string $accessToken,
        MonopayIframeCompatibility $iframeCompatibility,
    ): RedirectResponse|Response {
        $account = $this->account($request, $accountSlug);
        $order = $this->ticketOrder($account, $accessToken, ['edition']);
        $returnUrl = route('public.festival-orders.show', [$account->slug, $accessToken]);

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

        if (! $iframeCompatibility->allowsTicketIframe($request->userAgent())) {
            return redirect()->away($iframeCheckout['page_url']);
        }

        return response()
            ->view('festivals.public.order-payment', [
                'account' => $account,
                'order' => $order,
                'pageUrl' => $iframeCheckout['page_url'],
                'iframeOrigin' => $iframeCheckout['origin'],
                'returnUrl' => $returnUrl,
                'statusUrl' => route('public.festival-orders.status', [$account->slug, $accessToken]),
            ])
            ->withHeaders([
                ...$this->privateHeaders(),
                'Content-Security-Policy' => "frame-src {$iframeCheckout['origin']}; frame-ancestors 'self'",
                'Permissions-Policy' => "payment=(self \"{$iframeCheckout['origin']}\")",
            ]);
    }

    public function orderStatus(Request $request, string $accountSlug, string $accessToken): JsonResponse
    {
        $account = $this->account($request, $accountSlug);
        $order = FestivalTicketOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->with('edition:id,status,cancelled_at')
            ->with(['tickets' => fn ($query) => $query
                ->where('status', FestivalTicketStatus::Valid->value)
                ->whereHas('admissionType', fn ($query) => $query->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value))
                ->with('admissionType:id,name')
                ->orderBy('id')
                ->limit(1)])
            ->withCount([
                'tickets as valid_tickets_count' => fn ($query) => $query
                    ->where('status', FestivalTicketStatus::Valid->value)
                    ->whereHas('admissionType', fn ($query) => $query->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)),
            ])
            ->firstOrFail();
        $ticketsAvailable = $this->ticketsAreAvailable($order);
        $terminal = $order->status !== FestivalTicketOrderStatus::Pending;

        return response()->json([
            'status' => $order->status->value,
            'terminal' => $terminal,
            'paid' => $ticketsAvailable,
            'tickets_ready' => $ticketsAvailable && (int) $order->valid_tickets_count > 0,
            'festival_cancelled' => $order->edition->cancelled_at !== null
                || $order->edition->status === FestivalEditionStatus::Archived,
            'ticket' => $ticketsAvailable && $order->tickets->isNotEmpty() ? [
                'code' => $order->tickets->first()->code,
                'type' => $order->tickets->first()->admissionType?->name,
                'customer' => $order->tickets->first()->holder_name ?: $order->buyer_name,
            ] : null,
        ])->withHeaders($this->privateHeaders());
    }

    public function orderPdf(Request $request, string $accountSlug, string $accessToken, FestivalQrToken $qr): Response
    {
        $account = $this->account($request, $accountSlug);
        $order = FestivalTicketOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->where('status', FestivalTicketOrderStatus::Paid->value)
            ->whereHas('edition', fn ($query) => $query
                ->whereNull('cancelled_at')
                ->where('status', '!=', FestivalEditionStatus::Archived->value))
            ->with([
                'edition',
                'tickets' => fn ($query) => $query
                    ->where('status', FestivalTicketStatus::Valid->value)
                    ->whereHas('admissionType', fn ($query) => $query->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value))
                    ->with(['admissionType', 'orderItem']),
            ])
            ->firstOrFail();

        abort_if($order->tickets->isEmpty(), 404);

        $qrCodes = $order->tickets->mapWithKeys(fn ($ticket): array => [$ticket->id => $qr->dataUri($ticket)]);
        $venue = collect([$order->edition->venue_name, $order->edition->venue_address])->filter()->join(' · ');
        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ], true)
            ->setPaper('a4', 'portrait')
            ->loadView('festivals.public.tickets-pdf', compact('account', 'order', 'qrCodes', 'venue'));

        return $pdf
            ->download('festival-tickets-'.$order->order_id.'.pdf')
            ->withHeaders($this->privateHeaders());
    }

    public function ticketQr(Request $request, string $accountSlug, string $accessToken, string $ticketCode, FestivalQrToken $qr): Response
    {
        $account = $this->account($request, $accountSlug);
        $order = FestivalTicketOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->where('status', FestivalTicketOrderStatus::Paid->value)
            ->whereHas('edition', fn ($query) => $query
                ->whereNull('cancelled_at')
                ->where('status', '!=', FestivalEditionStatus::Archived->value))
            ->firstOrFail();
        $ticket = $order->tickets()
            ->where('code', $ticketCode)
            ->where('status', FestivalTicketStatus::Valid->value)
            ->whereHas('admissionType', fn ($query) => $query->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value))
            ->firstOrFail();

        return response($qr->png($ticket), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$ticket->code.'.png"',
            ...$this->privateHeaders(),
        ]);
    }

    /**
     * @param  list<string>  $with
     */
    private function ticketOrder(Account $account, string $accessToken, array $with = []): FestivalTicketOrder
    {
        return FestivalTicketOrder::query()
            ->whereBelongsTo($account)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->with($with)
            ->firstOrFail();
    }

    private function ticketsAreAvailable(FestivalTicketOrder $order): bool
    {
        return $order->status === FestivalTicketOrderStatus::Paid
            && $order->edition->cancelled_at === null
            && $order->edition->status !== FestivalEditionStatus::Archived;
    }

    private function paymentIsAvailable(FestivalTicketOrder $order): bool
    {
        $paymentDeadline = $order->payment_expires_at ?? $order->expires_at;

        return $order->status === FestivalTicketOrderStatus::Pending
            && in_array($order->edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
            && $order->edition->cancelled_at === null
            && $order->edition->ends_at->isFuture()
            && ($paymentDeadline === null || $paymentDeadline->isFuture());
    }

    /**
     * @return array{page_url: string, origin: string}|null
     */
    private function iframeCheckoutData(FestivalTicketOrder $order): ?array
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
    private function paymentLauncherData(FestivalTicketOrder $order): ?array
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

    private function account(Request $request, string $slug): Account
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $slug, 404);

        return $account;
    }
}
