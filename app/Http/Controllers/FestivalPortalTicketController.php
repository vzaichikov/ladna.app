<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalEntrancePassScanner;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntrancePass;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalQrToken;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FestivalPortalTicketController extends Controller
{
    public function __construct(private readonly FestivalEntrancePassScanner $scanner) {}

    public function index(Request $request, string $accountSlug, FestivalQrToken $qr): Response
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $passes = $portalUser->entrancePasses()
            ->where('festival_entrance_passes.account_id', $account->id)
            ->with(['edition.series', 'participant'])
            ->orderByDesc('festival_edition_id')
            ->orderBy('code')
            ->get();
        $usablePassIds = $passes
            ->filter(fn (FestivalEntrancePass $pass): bool => $this->scanner->canBeUsed($pass))
            ->pluck('id');
        $qrCodes = $passes
            ->whereIn('id', $usablePassIds)
            ->mapWithKeys(fn (FestivalEntrancePass $pass): array => [$pass->id => $qr->dataUri($pass)]);
        $friendOrders = $portalUser->purchasedTicketOrders()
            ->where('account_id', $account->id)
            ->with(['edition', 'tickets'])
            ->latest('id')
            ->get();
        $activeTab = $request->string('tab')->toString() === 'friends' ? 'friends' : 'passes';

        return response()->view('festivals.portal.tickets', compact(
            'account',
            'portalUser',
            'passes',
            'usablePassIds',
            'qrCodes',
            'friendOrders',
            'activeTab',
        ))->withHeaders($this->privateHeaders());
    }

    public function pdf(
        Request $request,
        string $accountSlug,
        FestivalEdition $festivalEdition,
        FestivalQrToken $qr,
    ): Response {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($festivalEdition->account_id === $account->id, 404);
        $passes = $this->usablePasses($portalUser, $festivalEdition);
        abort_if($passes->isEmpty(), 404);
        $qrCodes = $passes->mapWithKeys(fn (FestivalEntrancePass $pass): array => [$pass->id => $qr->dataUri($pass)]);
        $venue = collect([$festivalEdition->venue_name, $festivalEdition->venue_address])->filter()->join(' · ');
        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ], true)
            ->setPaper('a4', 'portrait')
            ->loadView('festivals.portal.passes-pdf', compact('account', 'festivalEdition', 'passes', 'qrCodes', 'venue'));

        return $pdf->download('festival-passes-'.$festivalEdition->slug.'.pdf')->withHeaders($this->privateHeaders());
    }

    public function email(
        Request $request,
        string $accountSlug,
        FestivalEdition $festivalEdition,
        FestivalNotificationOutbox $notifications,
    ): RedirectResponse {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($festivalEdition->account_id === $account->id, 404);
        $passes = $this->usablePasses($portalUser, $festivalEdition);
        abort_if($passes->isEmpty(), 404);

        $notifications->queueForEntrancePasses(
            $portalUser,
            $festivalEdition,
            $passes->count(),
            'manual:'.Str::uuid(),
        );

        return back()->with('status', __('app.festival_passes_email_queued', ['address' => $portalUser->email]));
    }

    /** @return Collection<int, FestivalEntrancePass> */
    private function usablePasses(FestivalPortalUser $portalUser, FestivalEdition $edition): Collection
    {
        return $portalUser->entrancePasses()
            ->where('festival_edition_id', $edition->id)
            ->with(['edition', 'participant'])
            ->get()
            ->filter(fn (FestivalEntrancePass $pass): bool => $this->scanner->canBeUsed($pass));
    }

    /** @return array{Account, FestivalPortalUser} */
    private function context(Request $request, string $accountSlug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account
            && $account->slug === $accountSlug
            && $portalUser instanceof FestivalPortalUser
            && $portalUser->account_id === $account->id, 404);

        return [$account, $portalUser];
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
