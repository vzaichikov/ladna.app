<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\CreateFestivalTicketOrder;
use App\Actions\Festivals\FestivalTicketIssuer;
use App\Enums\FestivalTicketOrderStatus;
use App\Http\Requests\FestivalAdmissionOrderRequest;
use App\Http\Requests\FestivalGoogleEmailPrefillRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalGoogleEmailPrefill;
use App\Support\Festivals\FestivalPaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class FestivalAdmissionController extends Controller
{
    public function store(FestivalAdmissionOrderRequest $request, string $accountSlug, string $editionSlug, CreateFestivalTicketOrder $createOrder, FestivalPaymentService $payments, FestivalTicketIssuer $tickets): RedirectResponse|View
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)->firstOrFail();
        $input = $request->orderInput();
        $input['locale'] = app()->getLocale();
        $order = $createOrder->execute($edition, $input);

        if ($order->amount_cents === 0) {
            DB::transaction(function () use ($order, $tickets): void {
                $lockedOrder = $order->newQuery()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $lockedOrder->forceFill(['status' => FestivalTicketOrderStatus::Paid, 'paid_at' => now(), 'expires_at' => null])->save();
                $tickets->execute($lockedOrder);
            }, 3);

            return redirect()->route('public.festival-orders.show', [$accountSlug, $order->access_token_encrypted]);
        }

        try {
            $checkout = $payments->startOrder($order);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        if ($checkout->isIframe()) {
            return redirect()->route('public.festival-orders.payment', [$accountSlug, $order->access_token_encrypted]);
        }

        return $checkout->isRedirect() ? redirect()->away($checkout->url) : view('payments.redirect-form', compact('account', 'checkout'));
    }

    public function google(
        FestivalGoogleEmailPrefillRequest $request,
        string $accountSlug,
        string $editionSlug,
        FestivalGoogleEmailPrefill $googleEmailPrefill,
    ): RedirectResponse {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);
        $edition = FestivalEdition::query()
            ->whereBelongsTo($account)
            ->published()
            ->where('slug', $editionSlug)
            ->firstOrFail();

        return $googleEmailPrefill->redirect($account, $edition, $request->checkoutDraft());
    }

    public function googleCallback(Request $request, FestivalGoogleEmailPrefill $googleEmailPrefill): RedirectResponse
    {
        try {
            $state = $googleEmailPrefill->consumeState($request);
        } catch (ModelNotFoundException|RuntimeException) {
            return redirect()->route('home')->withErrors(['google' => __('app.festival_google_prefill_failed')]);
        }

        $checkoutDraft = $state['checkout_draft'];

        try {
            $profile = $googleEmailPrefill->verifiedProfile($request);
        } catch (RuntimeException) {
            return redirect()
                ->route('public.festivals.show', [$state['account']->slug, $state['edition']->slug])
                ->withInput($checkoutDraft)
                ->withErrors(['google' => __('app.festival_google_prefill_failed')]);
        }

        if (blank($checkoutDraft['buyer_name'] ?? null) && filled($profile['name'])) {
            $checkoutDraft['buyer_name'] = $profile['name'];
        }
        $checkoutDraft['buyer_email'] = $profile['email'];
        $checkoutDraft['buyer_email_confirmation'] = $profile['email'];

        return redirect()
            ->route('public.festivals.show', [$state['account']->slug, $state['edition']->slug])
            ->withInput($checkoutDraft);
    }
}
