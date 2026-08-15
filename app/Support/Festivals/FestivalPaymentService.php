<?php

namespace App\Support\Festivals;

use App\Actions\Festivals\FestivalActivityRecorder;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Actions\Festivals\FestivalTicketIssuer;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\IntegrationSetting;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentCheckoutRequest;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FestivalPaymentService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly FestivalTicketIssuer $tickets,
        private readonly FestivalNotificationOutbox $notifications,
        private readonly FiscalReceiptService $fiscalReceipts,
        private readonly FestivalEntryStepCompletion $completion,
        private readonly FestivalEntryWorkflowState $workflowState,
        private readonly SubmitFestivalEntryStep $submitEntryStep,
        private readonly FestivalActivityRecorder $activity,
    ) {}

    public function startCharge(FestivalCharge $charge, string $provider): PaymentCheckout
    {
        return DB::transaction(function () use ($charge, $provider): PaymentCheckout {
            $entry = FestivalEntry::query()
                ->with([
                    'portalUser',
                    'edition.account',
                    'steps.workflowStep',
                    'steps.requirements.definition.edition',
                    'steps.requirements.submissions',
                    'steps.charges',
                ])
                ->whereKey($charge->festival_entry_id)
                ->lockForUpdate()
                ->firstOrFail();
            $charge = FestivalCharge::query()
                ->whereKey($charge->id)
                ->where('festival_entry_id', $entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $charge->setRelation('entry', $entry);
            if ($charge->festival_entry_step_id !== null) {
                $entryStep = FestivalEntryStep::query()
                    ->with(['workflowStep', 'requirements.definition.edition', 'requirements.submissions', 'charges'])
                    ->whereKey($charge->festival_entry_step_id)
                    ->where('festival_entry_id', $entry->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $charge->setRelation('entryStep', $entryStep);
                $this->workflowState->assertPaymentAvailable($entry, $entryStep);
                $this->completion->assertRequirementsComplete($entryStep, 'provider');
            }
            if ($charge->due_at?->isPast()) {
                throw ValidationException::withMessages(['provider' => __('app.festival_step_deadline_expired')]);
            }
            if (! in_array($charge->status, [FestivalChargeStatus::Pending, FestivalChargeStatus::Failed], true)) {
                throw ValidationException::withMessages(['provider' => __('app.festival_step_payment_required')]);
            }
            if ($charge->paymentAttempts()->where('status', FestivalPaymentStatus::Pending->value)->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['provider' => __('app.festival_payment_already_pending')]);
            }

            try {
                $setting = $this->setting($charge->entry->edition->account, $provider);
            } catch (PaymentGatewayException) {
                throw ValidationException::withMessages(['provider' => __('app.no_payment_methods_available')]);
            }
            $expiresAt = now()->addMinutes(30);
            if ($charge->due_at && $charge->due_at->lessThan($expiresAt)) {
                $expiresAt = $charge->due_at;
            }
            $attempt = FestivalPaymentAttempt::query()->create([
                'account_id' => $charge->account_id,
                'festival_charge_id' => $charge->id,
                'provider' => $provider,
                'order_id' => 'FCHP-'.Str::upper(Str::random(18)),
                'amount_cents' => $charge->amount_cents,
                'currency' => $charge->currency,
                'expires_at' => $expiresAt,
            ]);
            $charge->forceFill(['status' => FestivalChargeStatus::PaymentPending])->save();
            $gateway = $this->gateways->get($provider);
            $checkout = $gateway->start(new PaymentCheckoutRequest(
                reference: $attempt->order_id,
                amountCents: $attempt->amount_cents,
                currency: $attempt->currency,
                description: $charge->name,
                buyerName: $charge->entry->portalUser->displayName(),
                buyerEmail: $charge->entry->portalUser->email,
                buyerPhone: $charge->entry->portalUser->phone,
                locale: $charge->entry->portalUser->locale,
                returnUrl: route('festival.portal.entries.show', [$charge->entry->edition->account->slug, $charge->entry]),
                callbackUrl: route('api.v1.festival-payments.callbacks', $gateway->provider()->value),
                expiresAt: $attempt->expires_at,
            ), $setting);
            $attempt->forceFill(['gateway_checkout_payload' => $checkout->gatewayPayload])->save();
            $attempt->setRelation('charge', $charge);
            $this->activity->record($attempt, 'payment.started', $entry->edition, $entry->portalUser, [
                'provider' => $provider,
                'status' => $attempt->status->value,
            ]);

            return $checkout;
        }, 3);
    }

    public function startOrder(FestivalTicketOrder $order): PaymentCheckout
    {
        $order->loadMissing(['account', 'edition']);
        $setting = $this->setting($order->account, (string) $order->provider);
        $gateway = $this->gateways->get((string) $order->provider);
        $checkout = $gateway->start(new PaymentCheckoutRequest(
            reference: $order->order_id,
            amountCents: $order->amount_cents,
            currency: $order->currency,
            description: $order->edition->title,
            buyerName: $order->buyer_name,
            buyerEmail: $order->buyer_email,
            buyerPhone: $order->buyer_phone,
            locale: $order->locale,
            returnUrl: $order->festival_portal_user_id
                ? route('festival.portal.guest.dashboard', $order->account->slug)
                : route('public.festival-orders.show', [$order->account->slug, $order->access_token_encrypted]),
            callbackUrl: route('api.v1.festival-payments.callbacks', $gateway->provider()->value),
            expiresAt: $order->expires_at ?? now()->addMinutes(30),
        ), $setting);
        $order->forceFill(['gateway_checkout_payload' => $checkout->gatewayPayload])->save();

        return $checkout;
    }

    public function completeAttempt(FestivalPaymentAttempt $attempt, PaymentCallbackResult $callback): FestivalPaymentAttempt
    {
        $submitStepId = null;
        $becamePaid = false;
        $completed = DB::transaction(function () use ($attempt, $callback, &$submitStepId, &$becamePaid): FestivalPaymentAttempt {
            $attempt = FestivalPaymentAttempt::query()->with(['charge.entry.portalUser', 'charge.entry.edition', 'charge.entryStep'])->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->assertCallback($attempt->order_id, $attempt->amount_cents, $attempt->currency, $callback);
            if ($attempt->status === FestivalPaymentStatus::Paid) {
                return $attempt;
            }

            $previousStatus = $attempt->status;

            $status = match ($callback->status) {
                PaymentCallbackStatus::Paid => FestivalPaymentStatus::Paid,
                PaymentCallbackStatus::Failed => FestivalPaymentStatus::Failed,
                PaymentCallbackStatus::Cancelled => FestivalPaymentStatus::Cancelled,
                PaymentCallbackStatus::Expired => FestivalPaymentStatus::Expired,
                default => FestivalPaymentStatus::Pending,
            };
            $attempt->forceFill([
                'status' => $status,
                'gateway_invoice_id' => $callback->gatewayInvoiceId,
                'gateway_payment_id' => $callback->gatewayPaymentId,
                'gateway_status' => $callback->gatewayStatus,
                'last_callback_payload' => $callback->payload,
                'failure_reason' => $callback->failureReason,
                'paid_at' => $status === FestivalPaymentStatus::Paid ? ($callback->paidAt ?? now()) : null,
                'failed_at' => $status === FestivalPaymentStatus::Failed ? now() : null,
            ])->save();

            if ($status === FestivalPaymentStatus::Paid) {
                $becamePaid = true;
                $late = $attempt->expires_at?->isPast() || $attempt->charge->due_at?->isPast() || $attempt->charge->cancelled_at !== null;
                $attempt->charge->forceFill([
                    'status' => $late ? FestivalChargeStatus::PaidRequiresRefund : FestivalChargeStatus::Paid,
                    'paid_at' => $callback->paidAt ?? now(),
                ])->save();
                if (! $late && $attempt->charge->entryStep) {
                    $submitStepId = $attempt->charge->entryStep->id;
                }
                $this->notifications->queueForEntry($attempt->charge->entry, 'payment_paid', ['charge' => $attempt->charge->name, 'entry_code' => $attempt->charge->entry->code]);
            } elseif ($status !== FestivalPaymentStatus::Pending
                && ! in_array($attempt->charge->status, [FestivalChargeStatus::Paid, FestivalChargeStatus::PaidRequiresRefund, FestivalChargeStatus::Cancelled, FestivalChargeStatus::Refunded], true)
                && ! $attempt->charge->paymentAttempts()
                    ->whereKeyNot($attempt->id)
                    ->where('status', FestivalPaymentStatus::Pending->value)
                    ->exists()) {
                $attempt->charge->forceFill(['status' => FestivalChargeStatus::Failed])->save();
            }

            if ($status !== $previousStatus) {
                $this->activity->record($attempt, 'payment.status_changed', $attempt->charge->entry->edition, payload: [
                    'from_status' => $previousStatus->value,
                    'to_status' => $status->value,
                    'charge_status' => $attempt->charge->status->value,
                ]);
            }

            return $attempt->refresh();
        }, 3);

        if ($becamePaid) {
            $this->fiscalReceipts->fiscalizeFestivalPaymentAttempt($completed);
        }

        if ($submitStepId !== null) {
            $this->submitPaidEntryStep($submitStepId);
        }

        return $completed;
    }

    private function submitPaidEntryStep(int $stepId): void
    {
        $step = FestivalEntryStep::query()
            ->with(['entry.edition', 'entry.steps.workflowStep', 'workflowStep', 'requirements.definition', 'requirements.submissions', 'charges'])
            ->find($stepId);

        if (! $step
            || ! in_array($step->status, [FestivalEntryStepStatus::Draft, FestivalEntryStepStatus::ChangesRequested], true)
            || ! $this->completion->requirementsComplete($step)
            || ! $this->completion->chargesComplete($step)) {
            return;
        }

        try {
            $this->submitEntryStep->execute($step->entry, $step);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function completeOrder(FestivalTicketOrder $order, PaymentCallbackResult $callback): FestivalTicketOrder
    {
        $becamePaid = false;
        $completed = DB::transaction(function () use ($order, $callback, &$becamePaid): FestivalTicketOrder {
            $order = FestivalTicketOrder::query()->with(['items.admissionType', 'edition'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertCallback($order->order_id, $order->amount_cents, $order->currency, $callback);
            if (in_array($order->status, [FestivalTicketOrderStatus::Paid, FestivalTicketOrderStatus::PaidRequiresRefund, FestivalTicketOrderStatus::Refunded], true)) {
                return $order;
            }

            if ($callback->status === PaymentCallbackStatus::Paid) {
                $types = $order->items->pluck('festival_admission_type_id');
                FestivalAdmissionType::query()->whereKey($types)->orderBy('id')->lockForUpdate()->get();
                $capacity = $order->items->every(function ($item) use ($order): bool {
                    $other = (int) FestivalTicketOrderItem::query()
                        ->where('festival_admission_type_id', $item->festival_admission_type_id)
                        ->where('festival_ticket_order_id', '!=', $order->id)
                        ->whereHas('order', fn ($query) => $query->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                        ->sum('quantity');

                    return $other + $item->quantity <= $item->admissionType->inventory;
                });
                $onlineAccess = true;
                $onlineItems = $order->items->filter(fn ($item): bool => $item->admissionType->delivery_mode === FestivalAdmissionDeliveryMode::OnlineStream);
                if ($onlineItems->isNotEmpty()) {
                    $onlineItem = $onlineItems->first();
                    $portalUser = $order->festival_portal_user_id
                        ? FestivalPortalUser::query()
                            ->whereKey($order->festival_portal_user_id)
                            ->where('account_id', $order->account_id)
                            ->where('role', FestivalPortalRole::Guest->value)
                            ->where('is_active', true)
                            ->lockForUpdate()
                            ->first()
                        : null;
                    $stream = $onlineItem?->admissionType->festival_online_stream_id
                        ? FestivalOnlineStream::query()
                            ->whereKey($onlineItem->admissionType->festival_online_stream_id)
                            ->where('account_id', $order->account_id)
                            ->where('festival_edition_id', $order->festival_edition_id)
                            ->lockForUpdate()
                            ->first()
                        : null;
                    $hasConflict = $portalUser && $stream
                        ? FestivalTicketOrder::query()
                            ->whereKeyNot($order->id)
                            ->where('festival_portal_user_id', $portalUser->id)
                            ->where('festival_edition_id', $order->festival_edition_id)
                            ->whereHas('items.admissionType', fn ($query) => $query->where('festival_online_stream_id', $stream->id))
                            ->where(fn ($query) => $query
                                ->where(fn ($query) => $query
                                    ->where('status', FestivalTicketOrderStatus::Pending->value)
                                    ->where('expires_at', '>', now()))
                                ->orWhere(fn ($query) => $query
                                    ->where('status', FestivalTicketOrderStatus::Paid->value)
                                    ->whereHas('tickets.streamEntitlement', fn ($query) => $query->where('festival_online_stream_id', $stream->id))))
                            ->exists()
                        : true;
                    $onlineAccess = $onlineItems->count() === 1
                        && (int) $onlineItem?->quantity === 1
                        && $portalUser !== null
                        && $stream?->is_enabled
                        && ! $hasConflict;
                }
                $canIssue = $capacity && $onlineAccess;
                $order->forceFill([
                    'status' => $canIssue ? FestivalTicketOrderStatus::Paid : FestivalTicketOrderStatus::PaidRequiresRefund,
                    'paid_at' => $callback->paidAt ?? now(),
                    'expires_at' => null,
                    'gateway_invoice_id' => $callback->gatewayInvoiceId,
                    'gateway_payment_id' => $callback->gatewayPaymentId,
                    'gateway_status' => $callback->gatewayStatus,
                    'last_callback_payload' => $callback->payload,
                    'failure_reason' => $canIssue ? null : ($capacity ? 'online_access_conflict' : 'late_payment_no_inventory'),
                ])->save();
                if ($canIssue) {
                    $becamePaid = true;
                    $this->tickets->execute($order);
                }

                return $order->refresh();
            }

            $order->forceFill([
                'status' => match ($callback->status) {
                    PaymentCallbackStatus::Failed => FestivalTicketOrderStatus::Failed,
                    PaymentCallbackStatus::Cancelled => FestivalTicketOrderStatus::Cancelled,
                    PaymentCallbackStatus::Expired => FestivalTicketOrderStatus::Expired,
                    default => FestivalTicketOrderStatus::Pending,
                },
                'gateway_status' => $callback->gatewayStatus,
                'last_callback_payload' => $callback->payload,
                'failure_reason' => $callback->failureReason,
                'failed_at' => $callback->status === PaymentCallbackStatus::Failed ? now() : null,
            ])->save();

            return $order->refresh();
        }, 3);

        if ($becamePaid) {
            $this->fiscalReceipts->fiscalizeFestivalTicketOrder($completed);
        }

        return $completed;
    }

    private function setting(Account $account, string $provider): IntegrationSetting
    {
        return $this->gateways->availableSettingsFor($account)->first(fn (IntegrationSetting $setting): bool => $setting->provider->value === $provider)
            ?? throw new PaymentGatewayException('Festival payment integration is unavailable.');
    }

    private function assertCallback(string $reference, int $amount, string $currency, PaymentCallbackResult $callback): void
    {
        if ($callback->orderId !== $reference || ($callback->amountCents !== null && $callback->amountCents !== $amount) || ($callback->currency !== null && strtoupper($callback->currency) !== strtoupper($currency))) {
            throw new InvalidPaymentCallbackException('Callback does not match Festival payment.');
        }
    }
}
