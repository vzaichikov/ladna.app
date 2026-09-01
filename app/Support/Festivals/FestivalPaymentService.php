<?php

namespace App\Support\Festivals;

use App\Actions\Festivals\FestivalActivityRecorder;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Actions\Festivals\FestivalTicketIssuer;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPaymentAttemptCharge;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\IntegrationSetting;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\MonopayCheckoutSettings;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentCheckoutRequest;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\TicketPaymentTiming;
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
        private readonly MonopayCheckoutSettings $monopayCheckoutSettings,
        private readonly TicketPaymentTiming $ticketPaymentTiming,
    ) {}

    public function startCharge(FestivalCharge $charge, string $provider): PaymentCheckout
    {
        return DB::transaction(function () use ($charge, $provider): PaymentCheckout {
            $requestedCharge = FestivalCharge::query()
                ->whereKey($charge->id)
                ->where('festival_entry_id', $charge->festival_entry_id)
                ->where('account_id', $charge->account_id)
                ->firstOrFail();
            $entry = FestivalEntry::query()
                ->with([
                    'portalUser',
                    'edition.account',
                    'steps.workflowStep',
                    'steps.requirements.definition.edition',
                    'steps.requirements.selectedHelpers',
                    'steps.requirements.submissions',
                    'steps.charges',
                ])
                ->whereKey($requestedCharge->festival_entry_id)
                ->where('account_id', $requestedCharge->account_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($requestedCharge->festival_entry_step_id !== null) {
                $entryStep = FestivalEntryStep::query()
                    ->with(['workflowStep', 'requirements.definition.edition', 'requirements.selectedHelpers', 'requirements.submissions', 'charges'])
                    ->whereKey($requestedCharge->festival_entry_step_id)
                    ->where('festival_entry_id', $entry->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->workflowState->assertPaymentAvailable($entry, $entryStep);
                $this->completion->assertRequirementsComplete($entryStep, 'provider');
            }

            $preLockStepChargeIds = $requestedCharge->festival_entry_step_id === null
                ? collect([$requestedCharge->id])
                : FestivalCharge::query()
                    ->where('account_id', $requestedCharge->account_id)
                    ->where('festival_entry_id', $requestedCharge->festival_entry_id)
                    ->where('festival_entry_step_id', $requestedCharge->festival_entry_step_id)
                    ->orderBy('id')
                    ->pluck('id');
            $pendingAttempts = FestivalPaymentAttempt::query()
                ->with('allocations')
                ->where('account_id', $requestedCharge->account_id)
                ->where('status', FestivalPaymentStatus::Pending->value)
                ->whereHas('allocations', fn ($query) => $query->whereIn('festival_charge_id', $preLockStepChargeIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedStepCharges = FestivalCharge::query()
                ->where('account_id', $requestedCharge->account_id)
                ->where('festival_entry_id', $requestedCharge->festival_entry_id)
                ->when(
                    $requestedCharge->festival_entry_step_id === null,
                    fn ($query) => $query->whereKey($requestedCharge->id),
                    fn ($query) => $query->where('festival_entry_step_id', $requestedCharge->festival_entry_step_id),
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $charge = $lockedStepCharges->firstWhere('id', $requestedCharge->id) ?? throw ValidationException::withMessages([
                'provider' => __('app.festival_step_payment_required'),
            ]);
            $lockedCurrency = strtoupper($charge->currency);
            $scopeCharges = $lockedStepCharges
                ->filter(fn (FestivalCharge $scopeCharge): bool => strtoupper($scopeCharge->currency) === $lockedCurrency)
                ->values();
            $scopeChargeIds = $scopeCharges->modelKeys();
            $pendingAttempts = $pendingAttempts
                ->filter(fn (FestivalPaymentAttempt $pendingAttempt): bool => $pendingAttempt->allocations
                    ->contains(fn (FestivalPaymentAttemptCharge $allocation): bool => in_array($allocation->festival_charge_id, $scopeChargeIds, true)))
                ->values();
            $charge->setRelation('entry', $entry);
            if (isset($entryStep)) {
                $charge->setRelation('entryStep', $entryStep);
            }

            foreach ($pendingAttempts->filter(fn (FestivalPaymentAttempt $pendingAttempt): bool => $pendingAttempt->expires_at?->isPast() === true) as $expiredAttempt) {
                $expiredAttempt->forceFill(['status' => FestivalPaymentStatus::Expired])->save();
            }
            foreach ($scopeCharges->where('status', FestivalChargeStatus::PaymentPending) as $pendingCharge) {
                $hasLiveAttempt = $pendingAttempts->contains(fn (FestivalPaymentAttempt $pendingAttempt): bool => $pendingAttempt->status === FestivalPaymentStatus::Pending
                    && ($pendingAttempt->expires_at === null || $pendingAttempt->expires_at->isFuture())
                    && $pendingAttempt->allocations->contains('festival_charge_id', $pendingCharge->id));
                if (! $hasLiveAttempt) {
                    $pendingCharge->forceFill(['status' => FestivalChargeStatus::Failed])->save();
                }
            }

            $charges = $requestedCharge->festival_entry_step_id === null
                ? FestivalCharge::query()
                    ->whereKey($requestedCharge->id)
                    ->whereIn('status', [FestivalChargeStatus::Pending->value, FestivalChargeStatus::Failed->value])
                    ->where('amount_cents', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : FestivalCharge::query()
                    ->whereKey($scopeCharges->modelKeys())
                    ->whereIn('status', [FestivalChargeStatus::Pending->value, FestivalChargeStatus::Failed->value])
                    ->where('amount_cents', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            if (! $charges->contains('id', $charge->id)) {
                throw ValidationException::withMessages(['provider' => __('app.festival_step_payment_required')]);
            }
            if ($charges->contains(fn (FestivalCharge $groupedCharge): bool => $groupedCharge->due_at?->isPast() === true)) {
                throw ValidationException::withMessages(['provider' => __('app.festival_step_deadline_expired')]);
            }
            if (FestivalPaymentAttempt::query()
                ->where('status', FestivalPaymentStatus::Pending->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('allocations', fn ($query) => $query->whereIn('festival_charge_id', $charges->modelKeys()))
                ->exists()) {
                throw ValidationException::withMessages(['provider' => __('app.festival_payment_already_pending')]);
            }

            $leadCharge = $charges->first(fn (FestivalCharge $groupedCharge): bool => $groupedCharge->festival_charge_definition_id !== null)
                ?? $charges->firstOrFail();
            $leadCharge->setRelation('entry', $entry);

            try {
                $setting = $this->setting($entry->edition->account, $provider);
            } catch (PaymentGatewayException) {
                throw ValidationException::withMessages(['provider' => __('app.no_payment_methods_available')]);
            }
            $expiresAt = now()->addMinutes(30);
            $earliestDueAt = $charges->whereNotNull('due_at')->min('due_at');
            if ($earliestDueAt && $earliestDueAt->lessThan($expiresAt)) {
                $expiresAt = $earliestDueAt;
            }
            $attempt = FestivalPaymentAttempt::query()->create([
                'account_id' => $leadCharge->account_id,
                'festival_charge_id' => $leadCharge->id,
                'provider' => $provider,
                'order_id' => 'FCHP-'.Str::upper(Str::random(18)),
                'amount_cents' => (int) $charges->sum('amount_cents'),
                'currency' => $leadCharge->currency,
                'expires_at' => $expiresAt,
            ]);
            foreach ($charges as $groupedCharge) {
                $attempt->allocations()->create([
                    'account_id' => $groupedCharge->account_id,
                    'festival_charge_id' => $groupedCharge->id,
                    'amount_cents' => $groupedCharge->amount_cents,
                    'currency' => $groupedCharge->currency,
                ]);
            }
            FestivalCharge::query()->whereKey($charges->modelKeys())->update([
                'status' => FestivalChargeStatus::PaymentPending->value,
                'updated_at' => now(),
            ]);
            $gateway = $this->gateways->get($provider);
            $checkout = $gateway->start(new PaymentCheckoutRequest(
                reference: $attempt->order_id,
                amountCents: $attempt->amount_cents,
                currency: $attempt->currency,
                description: $leadCharge->name,
                buyerName: $entry->portalUser->displayName(),
                buyerEmail: $entry->portalUser->email,
                buyerPhone: $entry->portalUser->phone,
                locale: $entry->portalUser->locale,
                returnUrl: route('festival.portal.entries.show', [$entry->edition->account->slug, $entry]),
                callbackUrl: route('api.v1.festival-payments.callbacks', $gateway->provider()->value),
                expiresAt: $attempt->expires_at,
            ), $setting);
            $attempt->forceFill(['gateway_checkout_payload' => $checkout->gatewayPayload])->save();
            $attempt->setRelation('charge', $leadCharge);
            $this->activity->record($attempt, 'payment.started', $entry->edition, $entry->portalUser, [
                'provider' => $provider,
                'status' => $attempt->status->value,
                'charge_count' => $charges->count(),
            ]);

            return $checkout;
        }, 3);
    }

    public function startOrder(FestivalTicketOrder $order): PaymentCheckout
    {
        $order->loadMissing(['account', 'edition']);

        try {
            $setting = $this->setting($order->account, (string) $order->provider);
            $gateway = $this->gateways->get((string) $order->provider);
            $timing = $this->ticketPaymentTiming->resolve($setting);
            $order->forceFill([
                'payment_expires_at' => $timing['payment_expires_at'],
                'expires_at' => $timing['expires_at'],
            ])->save();
            $checkout = $gateway->start(new PaymentCheckoutRequest(
                reference: $order->order_id,
                amountCents: $order->amount_cents,
                currency: $order->currency,
                description: $order->edition->title,
                buyerName: $order->buyer_name,
                buyerEmail: $order->buyer_email,
                buyerPhone: $order->buyer_phone,
                locale: $order->locale,
                returnUrl: route('public.festival-orders.show', [$order->account->slug, $order->access_token_encrypted]),
                callbackUrl: route('api.v1.festival-payments.callbacks', $gateway->provider()->value),
                expiresAt: $timing['payment_expires_at'],
                preferIframe: $gateway->provider() === IntegrationProvider::Monopay
                    && $this->monopayCheckoutSettings->ticketIframeV2Enabled(),
                validitySeconds: $timing['validity_seconds'],
            ), $setting);
            $payload = [
                ...$checkout->gatewayPayload,
                '_launcher' => [
                    'type' => $checkout->type,
                    'url' => $checkout->url,
                    'method' => $checkout->method,
                    'fields' => $checkout->fields,
                ],
            ];
            $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
            $order->forceFill([
                'gateway_checkout_payload' => $payload,
                'gateway_invoice_id' => $response['invoiceId'] ?? null,
                'gateway_status' => $response['status'] ?? null,
            ])->save();
        } catch (Throwable $exception) {
            FestivalTicketOrder::query()
                ->whereKey($order->id)
                ->where('status', FestivalTicketOrderStatus::Pending->value)
                ->update([
                    'status' => FestivalTicketOrderStatus::Failed->value,
                    'payment_expires_at' => null,
                    'expires_at' => null,
                    'failure_reason' => $exception->getMessage(),
                    'failed_at' => now(),
                ]);

            throw $exception;
        }

        return $checkout;
    }

    public function completeAttempt(FestivalPaymentAttempt $attempt, PaymentCallbackResult $callback): FestivalPaymentAttempt
    {
        $submitStepId = null;
        $becamePaid = false;
        $completed = DB::transaction(function () use ($attempt, $callback, &$submitStepId, &$becamePaid): FestivalPaymentAttempt {
            $attempt = FestivalPaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->assertCallback($attempt->order_id, $attempt->amount_cents, $attempt->currency, $callback);

            if ($callback->isOlderThan($attempt->last_callback_payload)) {
                return $attempt->load(['charge.entry.portalUser', 'charge.entry.edition', 'charge.entryStep', 'allocations.charge']);
            }

            if ($attempt->status === FestivalPaymentStatus::Paid) {
                return $attempt->load(['charge.entry.portalUser', 'charge.entry.edition', 'charge.entryStep', 'allocations.charge']);
            }

            $allocations = FestivalPaymentAttemptCharge::query()
                ->where('festival_payment_attempt_id', $attempt->id)
                ->orderBy('festival_charge_id')
                ->lockForUpdate()
                ->get();
            if ($allocations->isEmpty()
                || (int) $allocations->sum('amount_cents') !== $attempt->amount_cents
                || ! $allocations->contains('festival_charge_id', $attempt->festival_charge_id)
                || $allocations->contains(fn (FestivalPaymentAttemptCharge $allocation): bool => $allocation->account_id !== $attempt->account_id
                    || strtoupper($allocation->currency) !== strtoupper($attempt->currency))) {
                throw new InvalidPaymentCallbackException('Festival payment allocations do not match the payment attempt.');
            }
            $chargeIds = $allocations->pluck('festival_charge_id');
            $charges = FestivalCharge::query()
                ->with(['entry.portalUser', 'entry.edition', 'entryStep'])
                ->where('account_id', $attempt->account_id)
                ->whereKey($chargeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($charges->count() !== $chargeIds->unique()->count()
                || $charges->pluck('festival_entry_id')->unique()->count() !== 1
                || $charges->pluck('festival_entry_step_id')->unique()->count() !== 1
                || $allocations->contains(function (FestivalPaymentAttemptCharge $allocation) use ($charges): bool {
                    $allocatedCharge = $charges->firstWhere('id', $allocation->festival_charge_id);

                    return ! $allocatedCharge
                        || $allocatedCharge->account_id !== $allocation->account_id
                        || strtoupper($allocatedCharge->currency) !== strtoupper($allocation->currency);
                })) {
                throw new InvalidPaymentCallbackException('Festival payment allocations cross payment boundaries.');
            }
            $leadCharge = $charges->firstWhere('id', $attempt->festival_charge_id) ?? $charges->firstOrFail();
            $attempt->setRelation('charge', $leadCharge);
            $attempt->setRelation('allocations', $allocations->each(
                fn (FestivalPaymentAttemptCharge $allocation) => $allocation->setRelation('charge', $charges->firstWhere('id', $allocation->festival_charge_id)),
            ));

            $previousStatus = $attempt->status;

            $status = $previousStatus !== FestivalPaymentStatus::Pending && $callback->status !== PaymentCallbackStatus::Paid
                ? $previousStatus
                : match ($callback->status) {
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
                $sharedLate = $attempt->expires_at?->isPast() === true;
                $allChargesSettled = ! $sharedLate;
                foreach ($charges as $allocatedCharge) {
                    $hasOtherPaidAttempt = FestivalPaymentAttempt::query()
                        ->whereKeyNot($attempt->id)
                        ->where('status', FestivalPaymentStatus::Paid->value)
                        ->whereHas('allocations', fn ($query) => $query->where('festival_charge_id', $allocatedCharge->id))
                        ->exists();
                    $requiresRefund = $sharedLate
                        || $hasOtherPaidAttempt
                        || $allocatedCharge->due_at?->isPast() === true
                        || $allocatedCharge->cancelled_at !== null;
                    $allChargesSettled = $allChargesSettled && ! $requiresRefund;
                    $allocatedCharge->forceFill([
                        'status' => $requiresRefund ? FestivalChargeStatus::PaidRequiresRefund : FestivalChargeStatus::Paid,
                        'paid_at' => $callback->paidAt ?? now(),
                    ])->save();
                }
                if ($allChargesSettled && $charges->first()->festival_entry_step_id !== null) {
                    $submitStepId = $charges->first()->festival_entry_step_id;
                }
                $this->notifications->queueForEntry($leadCharge->entry, 'payment_paid', ['charge' => $leadCharge->name, 'entry_code' => $leadCharge->entry->code]);
            } elseif ($status !== FestivalPaymentStatus::Pending) {
                foreach ($charges as $allocatedCharge) {
                    $hasPendingAttempt = FestivalPaymentAttempt::query()
                        ->whereKeyNot($attempt->id)
                        ->where('status', FestivalPaymentStatus::Pending->value)
                        ->whereHas('allocations', fn ($query) => $query->where('festival_charge_id', $allocatedCharge->id))
                        ->exists();

                    if (! $hasPendingAttempt && ! in_array($allocatedCharge->status, [FestivalChargeStatus::Paid, FestivalChargeStatus::PaidRequiresRefund, FestivalChargeStatus::Cancelled, FestivalChargeStatus::Refunded], true)) {
                        $allocatedCharge->forceFill(['status' => FestivalChargeStatus::Failed])->save();
                    }
                }
            }

            if ($status !== $previousStatus) {
                $this->activity->record($attempt, 'payment.status_changed', $leadCharge->entry->edition, payload: [
                    'from_status' => $previousStatus->value,
                    'to_status' => $status->value,
                    'charge_status' => $leadCharge->refresh()->status->value,
                    'charge_count' => $charges->count(),
                ]);
            }

            return $attempt->refresh()->load(['charge.entry.portalUser', 'charge.entry.edition', 'charge.entryStep', 'allocations.charge']);
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
            ->with(['entry.edition', 'entry.steps.workflowStep', 'workflowStep', 'requirements.definition', 'requirements.selectedHelpers', 'requirements.submissions', 'charges'])
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
        [$completed, $transition] = DB::transaction(function () use ($order, $callback): array {
            $order = FestivalTicketOrder::query()->with(['items.admissionType', 'edition'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertCallback($order->order_id, $order->amount_cents, $order->currency, $callback);

            if ($callback->isOlderThan($order->last_callback_payload)) {
                return [$order, null];
            }

            $isTerminal = $order->refunded_at !== null
                || in_array($order->status, [FestivalTicketOrderStatus::Paid, FestivalTicketOrderStatus::Refunded], true)
                || ($order->status === FestivalTicketOrderStatus::PaidRequiresRefund
                    && $order->provider !== IntegrationProvider::Monopay->value);

            if ($isTerminal
                || ($order->status === FestivalTicketOrderStatus::PaidRequiresRefund && $callback->status !== PaymentCallbackStatus::Paid)) {
                return [$order, null];
            }

            if ($callback->status === PaymentCallbackStatus::Paid) {
                $types = $order->items->pluck('festival_admission_type_id');
                FestivalAdmissionType::query()->whereKey($types)->orderBy('id')->lockForUpdate()->get();
                $onlineAccess = true;
                $onlineItems = $order->items->filter(fn ($item): bool => $item->admissionType->delivery_mode === FestivalAdmissionDeliveryMode::OnlineStream);
                $lateMonopayPayment = $order->provider === IntegrationProvider::Monopay->value
                    && ($order->status !== FestivalTicketOrderStatus::Pending
                        || $order->payment_expires_at?->isPast()
                        || $order->expires_at?->isPast());
                $lateMonopayVenuePayment = $lateMonopayPayment && $onlineItems->isEmpty();
                $editionAcceptsPayment = in_array($order->edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
                    && $order->edition->cancelled_at === null
                    && $order->edition->ends_at->isFuture();
                $capacity = true;

                if (! $lateMonopayVenuePayment) {
                    $capacity = $order->items->every(function ($item) use ($order): bool {
                        $other = (int) FestivalTicketOrderItem::query()
                            ->where('festival_admission_type_id', $item->festival_admission_type_id)
                            ->where('festival_ticket_order_id', '!=', $order->id)
                            ->whereHas('order', fn ($query) => $query->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                            ->sum('quantity');

                        return $other + $item->quantity <= $item->admissionType->inventory;
                    });
                }

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
                $canIssue = $editionAcceptsPayment && $capacity && $onlineAccess;
                $order->forceFill([
                    'status' => $canIssue ? FestivalTicketOrderStatus::Paid : FestivalTicketOrderStatus::PaidRequiresRefund,
                    'paid_at' => $callback->paidAt ?? now(),
                    'expires_at' => null,
                    'gateway_invoice_id' => $callback->gatewayInvoiceId,
                    'gateway_payment_id' => $callback->gatewayPaymentId,
                    'gateway_status' => $callback->gatewayStatus,
                    'last_callback_payload' => $callback->payload,
                    'failure_reason' => $canIssue ? null : match (true) {
                        ! $editionAcceptsPayment => 'festival_unavailable',
                        ! $capacity => 'late_payment_no_inventory',
                        default => 'online_access_conflict',
                    },
                    'failed_at' => null,
                ])->save();
                if ($canIssue) {
                    $this->tickets->execute(
                        $order,
                        $order->source === FestivalTicketOrderSource::Entrance
                            ? [['holder_name' => $order->buyer_name]]
                            : [],
                    );
                }

                return [$order->refresh(), $canIssue ? 'paid' : 'requires_refund'];
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

            return [$order->refresh(), null];
        }, 3);

        if ($transition === 'paid') {
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
