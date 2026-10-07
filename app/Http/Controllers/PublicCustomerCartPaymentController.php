<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayCustomerCartRequest;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Support\Payments\PaymentCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class PublicCustomerCartPaymentController extends Controller
{
    public function show(string $accountSlug, string $accessToken): Response
    {
        [$account, $purchase] = $this->context($accountSlug, $accessToken);
        $checkout = $this->checkout($purchase);

        return response()->view('public.customer-cart-payment', [
            'account' => $account,
            'purchase' => $purchase,
            'paymentActionUrl' => $purchase->paymentWindowIsOpen() && $checkout ? route('public.customer-cart.pay', [$accountSlug, $accessToken]) : null,
            'statusUrl' => route('public.customer-cart.status', [$accountSlug, $accessToken]),
        ])->withHeaders($this->privateHeaders());
    }

    public function pay(PayCustomerCartRequest $request, string $accountSlug, string $accessToken): Response|RedirectResponse
    {
        [$account, $purchase] = $this->context($accountSlug, $accessToken);
        $checkout = $this->checkout($purchase);
        if (! $purchase->paymentWindowIsOpen() || ! $checkout) {
            return redirect()->route('public.customer-cart.payment', [$accountSlug, $accessToken])->withHeaders($this->privateHeaders());
        }
        if ($checkout->isRedirect() || $checkout->isIframe()) {
            return redirect()->away($checkout->url)->withHeaders($this->privateHeaders());
        }

        return response()->view('payments.redirect-form', compact('account', 'purchase', 'checkout'))->withHeaders($this->privateHeaders());
    }

    public function status(string $accountSlug, string $accessToken): JsonResponse
    {
        [, $purchase] = $this->context($accountSlug, $accessToken);

        return response()->json([
            'status' => $purchase->status->value,
            'terminal' => $purchase->status->isFinal(),
            'paid' => $purchase->isPaid(),
        ])->withHeaders($this->privateHeaders());
    }

    /** @return array{0:Account, 1:CustomerPurchase} */
    private function context(string $accountSlug, string $accessToken): array
    {
        abort_unless(strlen($accessToken) === 64, 404);
        $account = Account::query()->active()->where('slug', $accountSlug)->firstOrFail();
        app()->setLocale(session('locale', $account->default_language ?: config('app.locale')));
        $purchase = CustomerPurchase::query()->whereBelongsTo($account)->where('access_token_hash', hash('sha256', $accessToken))->whereHas('items')->firstOrFail();
        $purchase->expirePaymentWindow()->load('items.customerClassPass');

        return [$account, $purchase];
    }

    private function checkout(CustomerPurchase $purchase): ?PaymentCheckout
    {
        $saved = $purchase->gateway_checkout_payload['cart_checkout'] ?? null;
        if (! is_array($saved) || ! is_string($saved['url'] ?? null)) {
            return null;
        }

        return match ($saved['type'] ?? null) {
            'redirect' => PaymentCheckout::redirect($saved['url']),
            'iframe' => PaymentCheckout::iframe($saved['url']),
            'form' => PaymentCheckout::form($saved['url'], $saved['fields'] ?? [], method: $saved['method'] ?? 'POST'),
            default => null,
        };
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
