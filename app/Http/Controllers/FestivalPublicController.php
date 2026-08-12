<?php

namespace App\Http\Controllers;

use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalTicketOrder;
use App\Support\Festivals\FestivalLandingRegistry;
use App\Support\Festivals\FestivalQrToken;
use App\Support\Payments\PaymentGatewayRegistry;
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
        PaymentGatewayRegistry $gateways,
        FestivalLandingRegistry $landingRegistry,
    ): View {
        $account = $this->account($request, $accountSlug);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)
            ->with(['series', 'sections' => fn ($query) => $query->where('visibility', 'public')->where('is_active', true), 'media' => fn ($query) => $query->where('is_active', true), 'stages', 'admissionTypes' => fn ($query) => $query->availableForSale(), 'results' => fn ($query) => $query->whereNotNull('published_at'), 'results.entry.category'])
            ->firstOrFail();
        $providers = $gateways->availableSettingsFor($account);
        $landingTemplateKey = $landingRegistry->effectiveTemplateKey($edition, $account);
        $landingPaletteKey = $landingRegistry->effectivePaletteKey($edition);
        $landingTemplate = $landingRegistry->template($landingTemplateKey);

        return view($landingTemplate['view'], compact('account', 'edition', 'providers', 'landingTemplateKey', 'landingPaletteKey'));
    }

    public function order(Request $request, string $accountSlug, string $accessToken, FestivalQrToken $qr): View
    {
        $account = $this->account($request, $accountSlug);
        $order = FestivalTicketOrder::query()->whereBelongsTo($account)->where('access_token_hash', hash('sha256', $accessToken))->with(['edition', 'items', 'tickets.admissionType'])->firstOrFail();
        $qrCodes = $order->tickets->filter(fn ($ticket): bool => $ticket->status === FestivalTicketStatus::Valid)->mapWithKeys(fn ($ticket): array => [$ticket->id => $qr->dataUri($ticket)]);

        return view('festivals.public.order', compact('account', 'order', 'qrCodes'));
    }

    public function ticketQr(Request $request, string $accountSlug, string $accessToken, string $ticketCode, FestivalQrToken $qr): Response
    {
        $account = $this->account($request, $accountSlug);
        $order = FestivalTicketOrder::query()->whereBelongsTo($account)->where('access_token_hash', hash('sha256', $accessToken))->where('status', FestivalTicketOrderStatus::Paid->value)->firstOrFail();
        $ticket = $order->tickets()->where('code', $ticketCode)->where('status', FestivalTicketStatus::Valid->value)->firstOrFail();

        return response($qr->png($ticket), 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="'.$ticket->code.'.png"', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function account(Request $request, string $slug): Account
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $slug, 404);

        return $account;
    }
}
