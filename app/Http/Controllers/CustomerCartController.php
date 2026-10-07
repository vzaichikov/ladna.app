<?php

namespace App\Http\Controllers;

use App\Actions\Payments\CompleteCustomerPurchase;
use App\Actions\Payments\CreateAdminCustomerPurchase;
use App\Actions\Payments\StartAdminCustomerPurchasePayment;
use App\Http\Requests\CheckoutCustomerCartRequest;
use App\Http\Requests\QuoteCustomerCartRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Support\Entrance\EntranceQrCode;
use App\Support\Payments\CustomerCartQuote;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerCartController extends Controller
{
    public function quote(QuoteCustomerCartRequest $request, Account $account, Customer $customer, CustomerCartQuote $quotes): JsonResponse
    {
        $this->ensureCustomer($account, $customer);
        $input = $request->validated();
        if (! $account->locations()->active()->whereKey($input['location_id'])->exists()) {
            throw ValidationException::withMessages(['location_id' => __('app.customer_cart_location_unavailable')]);
        }
        $quote = $quotes->execute($account, $customer, $input['items'], $input['promo_code'] ?? null);
        unset($quote['items'], $quote['promotion']);

        return response()->json($quote)->header('Cache-Control', 'private, no-store');
    }

    public function checkout(
        CheckoutCustomerCartRequest $request,
        Account $account,
        Customer $customer,
        CreateAdminCustomerPurchase $create,
        CompleteCustomerPurchase $complete,
        StartAdminCustomerPurchasePayment $start,
        EntranceQrCode $qr,
    ): JsonResponse {
        $this->ensureCustomer($account, $customer);
        $purchase = DB::transaction(function () use ($request, $account, $customer, $create, $complete): CustomerPurchase {
            $purchase = $create->execute($account, $customer, $request->user(), $request->validated());
            if (! $purchase->isPaid() && ($purchase->amount_cents === 0 || $purchase->paymentMethod() !== CustomerPurchase::PaymentMethodOnline)) {
                $purchase = $complete->execute($purchase, new PaymentCallbackResult(
                    orderId: $purchase->order_id,
                    status: PaymentCallbackStatus::Paid,
                    amountCents: $purchase->amount_cents,
                    currency: $purchase->currency,
                    paidAt: now(),
                ));
            }

            return $purchase;
        });
        if ($purchase->amount_cents > 0 && $purchase->paymentMethod() === CustomerPurchase::PaymentMethodOnline && $purchase->paymentWindowIsOpen()) {
            $purchase = $start->execute($purchase);
        }

        return $this->purchaseResponse($account, $customer, $purchase, $qr, 201);
    }

    public function status(Request $request, Account $account, Customer $customer, CustomerPurchase $customerPurchase, EntranceQrCode $qr): JsonResponse
    {
        $this->ensureCustomer($account, $customer);
        $this->authorize('manageClients', $account);
        $this->authorize('issueCustomerClassPasses', $account);
        $this->authorize('recordCustomerPayments', $account);
        abort_unless($customerPurchase->account_id === $account->id && $customerPurchase->customer_id === $customer->id && $customerPurchase->hasItems(), 404);

        return $this->purchaseResponse($account, $customer, $customerPurchase, $qr);
    }

    private function purchaseResponse(Account $account, Customer $customer, CustomerPurchase $purchase, EntranceQrCode $qr, int $status = 200): JsonResponse
    {
        $purchase->expirePaymentWindow()->loadMissing('items.customerClassPass');
        $statusUrl = route('dashboard.accounts.customers.cart.status', [$account, $customer, $purchase]);
        $paymentUrl = $purchase->paymentWindowIsOpen() && $purchase->paymentMethod() === CustomerPurchase::PaymentMethodOnline
            ? route('public.customer-cart.payment', [$account->slug, $purchase->access_token_encrypted]) : null;

        return response()->json([
            'purchase_id' => $purchase->id,
            'status' => $purchase->status->value,
            'paid' => $purchase->isPaid(),
            'terminal' => $purchase->status->isFinal(),
            'message' => $purchase->isPaid() ? __('app.customer_cart_paid') : ($purchase->status->isFinal() ? __('app.class_pass_checkout_status_'.$purchase->status->value) : __('app.customer_cart_pending')),
            'items' => $purchase->isPaid() ? $purchase->items->map(fn ($item): array => ['code' => $item->customerClassPass?->code, 'plan_name' => $item->plan_name])->values()->all() : [],
            'payment' => $paymentUrl ? ['url' => $paymentUrl, 'status_url' => $statusUrl, 'qr_data_uri' => $qr->dataUri($paymentUrl)] : null,
        ], $status)->header('Cache-Control', 'private, no-store');
    }

    private function ensureCustomer(Account $account, Customer $customer): void
    {
        abort_unless($customer->account_id === $account->id, 404);
    }
}
