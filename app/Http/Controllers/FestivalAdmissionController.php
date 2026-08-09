<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\CreateFestivalTicketOrder;
use App\Actions\Festivals\FestivalTicketIssuer;
use App\Enums\FestivalTicketOrderStatus;
use App\Http\Requests\FestivalAdmissionOrderRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FestivalAdmissionController extends Controller
{
    public function store(FestivalAdmissionOrderRequest $request, string $accountSlug, string $editionSlug, CreateFestivalTicketOrder $createOrder, FestivalPaymentService $payments, FestivalTicketIssuer $tickets): RedirectResponse|View
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)->firstOrFail();
        $portalUser = $request->user('festival');
        if (! $portalUser instanceof FestivalPortalUser || $portalUser->account_id !== $account->id) {
            $portalUser = null;
        }
        $order = $createOrder->execute($edition, $request->validated(), $portalUser);

        if ($order->amount_cents === 0) {
            $order->forceFill(['status' => FestivalTicketOrderStatus::Paid, 'paid_at' => now(), 'expires_at' => null])->save();
            $tickets->execute($order);

            return redirect()->route('public.festival-orders.show', [$accountSlug, $order->access_token_encrypted]);
        }

        try {
            $checkout = $payments->startOrder($order);
        } catch (Throwable $exception) {
            report($exception);
            $order->forceFill(['status' => FestivalTicketOrderStatus::Failed, 'failed_at' => now(), 'failure_reason' => $exception->getMessage()])->save();
            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        return $checkout->isRedirect() ? redirect()->away($checkout->url) : view('payments.redirect-form', compact('account', 'checkout'));
    }
}
