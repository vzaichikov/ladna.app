<?php

namespace App\Http\Controllers;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTimeline;
use App\Support\Festivals\FestivalLandingRegistry;
use App\Support\Festivals\FestivalQrToken;
use App\Support\Festivals\FestivalTimelinePresenter;
use App\Support\Payments\MonopayGateway;
use App\Support\Payments\PaymentCheckout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    ): View {
        $account = $this->account($request, $accountSlug);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)
            ->with(['series', 'sections' => fn ($query) => $query->where('visibility', 'public')->where('is_active', true), 'media' => fn ($query) => $query->where('is_active', true), 'stages', 'admissionTypes' => fn ($query) => $query->availableForSale(), 'results' => fn ($query) => $query->whereNotNull('published_at'), 'results.entry.category'])
            ->firstOrFail();
        $landingTemplateKey = $landingRegistry->effectiveTemplateKey($edition, $account);
        $landingPaletteKey = $landingRegistry->effectivePaletteKey($edition);
        $landingTemplate = $landingRegistry->template($landingTemplateKey);
        $publicTemplateData = $landingTemplateKey === 'velvet_night'
            ? $this->velvetPublicContent($edition)
            : $this->emptyStructuredPublicContent($edition);
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

        return view($landingTemplate['view'], [
            ...compact(
                'account',
                'edition',
                'landingTemplateKey',
                'landingPaletteKey',
                'publicTimelineViews',
                'timelineWithinDates',
                'timelinePollingUrl',
            ),
            ...$publicTemplateData,
        ]);
    }

    public function order(Request $request, string $accountSlug, string $accessToken, FestivalQrToken $qr): Response
    {
        $account = $this->account($request, $accountSlug);
        $order = $this->ticketOrder($account, $accessToken, [
            'edition',
            'items',
            'tickets.admissionType',
            'tickets.orderItem',
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

    public function orderPayment(Request $request, string $accountSlug, string $accessToken): RedirectResponse|Response
    {
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

    /** @return array<string, Collection> */
    private function emptyStructuredPublicContent(FestivalEdition $edition): array
    {
        return [
            'publicContentSections' => $edition->sections,
            'structuredContentSections' => collect(),
            'publicDates' => collect(),
            'publicStages' => collect(),
            'publicFees' => collect(),
        ];
    }

    /** @return array<string, Collection> */
    private function velvetPublicContent(FestivalEdition $edition): array
    {
        $edition->load([
            'workflows' => fn ($query) => $query->where('is_active', true)->with([
                'steps' => fn ($stepQuery) => $stepQuery->where('is_active', true)->whereNotNull('due_at'),
            ]),
            'festivalChargeDefinitions' => fn ($query) => $query->where('is_active', true)->with('workflowStep'),
            'stages' => fn ($query) => $query->where('is_active', true),
        ]);

        $structuredContentSections = $edition->sections
            ->whereIn('key', ['important-dates', 'jury', 'stage', 'payments'])
            ->keyBy('key');

        return [
            'publicContentSections' => $edition->sections
                ->whereNotIn('key', $structuredContentSections->keys()->all())
                ->values(),
            'structuredContentSections' => $structuredContentSections,
            'publicDates' => $this->velvetDates($edition),
            'publicStages' => $edition->stages,
            'publicFees' => $edition->festivalChargeDefinitions
                ->unique(fn ($fee): string => implode('|', [
                    $fee->name,
                    (string) $fee->amount_cents,
                    $fee->currency,
                    $fee->pricing_mode->value,
                    (string) $fee->included_members,
                    (string) $fee->additional_member_amount_cents,
                ]))
                ->values(),
        ];
    }

    /** @return Collection<int, array{label: string, date: string}> */
    private function velvetDates(FestivalEdition $edition): Collection
    {
        $dates = collect();

        if ($edition->registration_opens_at) {
            $dates->push(['label' => __('app.festival_registration_opens'), 'at' => $edition->registration_opens_at]);
        }

        if ($edition->registration_closes_at) {
            $dates->push(['label' => __('app.festival_registration_closes'), 'at' => $edition->registration_closes_at]);
        }

        foreach ($edition->workflows->flatMap->steps as $step) {
            $dates->push(['label' => $step->title, 'at' => $step->due_at]);
        }

        foreach ($edition->festivalChargeDefinitions as $fee) {
            $dueAt = $fee->due_at ?? $fee->due_hard_cap_at;

            if ($dueAt) {
                $dates->push(['label' => $fee->name, 'at' => $dueAt]);
            }
        }

        if ($edition->starts_at) {
            $dates->push(['label' => $edition->title, 'at' => $edition->starts_at]);
        }

        return $dates
            ->unique(fn (array $date): string => $date['label'].'|'.$date['at']->timestamp)
            ->sortBy(fn (array $date): int => $date['at']->timestamp)
            ->map(fn (array $date): array => [
                'label' => $date['label'],
                'date' => $date['at']->copy()->timezone($edition->timezone)->format('d.m.Y'),
            ])
            ->values();
    }
}
