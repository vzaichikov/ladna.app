<?php

namespace App\Http\Controllers;

use App\Actions\RecordCustomerPurchaseRefund;
use App\Enums\FiscalReceiptStatus;
use App\Http\Requests\StoreCustomerPurchaseRefundRequest;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Support\Fiscalization\FiscalReceiptService;
use Illuminate\Http\RedirectResponse;

class CustomerPurchaseRefundController extends Controller
{
    public function store(
        StoreCustomerPurchaseRefundRequest $request,
        Account $account,
        CustomerPurchase $customerPurchase,
        RecordCustomerPurchaseRefund $recordCustomerPurchaseRefund,
        FiscalReceiptService $fiscalReceiptService,
    ): RedirectResponse {
        abort_unless($customerPurchase->account_id === $account->id, 404);

        $cashLocation = $request->cashLocationId()
            ? $account->locations()->whereKey($request->cashLocationId())->firstOrFail()
            : null;
        $refund = $recordCustomerPurchaseRefund->execute(
            $account,
            $customerPurchase,
            $request->method(),
            $cashLocation,
            $request->amountCents(),
            now()->toImmutable(),
            $request->user(),
            (string) $request->validated('reason'),
            (string) $request->validated('idempotency_key'),
        );
        $receipt = $fiscalReceiptService->fiscalizeCustomerPurchaseRefund($refund);
        $message = $receipt?->status === FiscalReceiptStatus::Failed
            ? __('app.payment_refund_saved_fiscal_failed')
            : __('app.payment_refund_saved');

        return back()->with('status', $message);
    }
}
