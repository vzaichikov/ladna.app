<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ReconcileFestivalPaymentAttempt;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPaymentStatus;
use App\Models\Account;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalChargePaymentGroups;
use App\Support\Festivals\FestivalEntryStepCompletion;
use App\Support\Festivals\FestivalEntryWorkflowState;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\MonopayGateway;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FestivalChargeRecoveryController extends Controller
{
    public function status(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalCharge $festivalCharge): JsonResponse
    {
        $account = $this->authorizePortal($request, $festivalEntry, $festivalCharge);

        return $this->paymentResponse($account, $festivalEntry, $festivalCharge);
    }

    public function check(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalCharge $festivalCharge, ReconcileFestivalPaymentAttempt $reconcile): JsonResponse|RedirectResponse
    {
        $account = $this->authorizePortal($request, $festivalEntry, $festivalCharge);
        $this->checkAttempt($festivalCharge, $reconcile);

        if ($request->expectsJson()) {
            return $this->paymentResponse($account, $festivalEntry, $festivalCharge, __('app.festival_payment_checked'));
        }

        return back()->with('status', __('app.festival_payment_checked'));
    }

    public function resume(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalCharge $festivalCharge, ReconcileFestivalPaymentAttempt $reconcile, FestivalEntryWorkflowState $workflowState, FestivalEntryStepCompletion $completion): RedirectResponse
    {
        $this->authorizePortal($request, $festivalEntry, $festivalCharge);
        abort_if(in_array($festivalEntry->status, [FestivalEntryStatus::Withdrawn, FestivalEntryStatus::Rejected], true), 409);
        $festivalCharge->loadMissing('entryStep');
        if ($festivalCharge->entryStep) {
            $workflowState->assertPaymentAvailable($festivalEntry, $festivalCharge->entryStep);
            $completion->assertRequirementsComplete($festivalCharge->entryStep, 'provider');
        } else {
            abort_unless($festivalEntry->steps()->doesntExist(), 409);
        }
        $attempt = $this->checkAttempt($festivalCharge, $reconcile);
        $festivalCharge->refresh();
        $festivalEntry->refresh();
        $url = data_get($attempt->gateway_checkout_payload, 'response.pageUrl');

        if ($attempt->status !== FestivalPaymentStatus::Pending
            || $attempt->gateway_status !== 'created'
            || $attempt->expires_at?->isFuture() !== true
            || $festivalCharge->status !== FestivalChargeStatus::PaymentPending
            || $festivalCharge->due_at?->isPast()
            || in_array($festivalEntry->status, [FestivalEntryStatus::Withdrawn, FestivalEntryStatus::Rejected], true)
            || ! is_string($url)
            || ! MonopayGateway::trustedIframeOrigin($url)) {
            return redirect()->route('festival.portal.entries.show', [$accountSlug, $festivalEntry])
                ->with('status', __('app.festival_payment_checked'));
        }

        $festivalCharge->load('entryStep');
        if ($festivalCharge->entryStep) {
            $workflowState->assertPaymentAvailable($festivalEntry, $festivalCharge->entryStep);
            $completion->assertRequirementsComplete($festivalCharge->entryStep, 'provider');
        } else {
            abort_unless($festivalEntry->steps()->doesntExist(), 409);
        }

        return redirect()->away($url)->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function checkStaff(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCharge $festivalCharge, ReconcileFestivalPaymentAttempt $reconcile): RedirectResponse
    {
        abort_unless($festivalEdition->account_id === $account->id
            && $festivalCharge->account_id === $account->id
            && $festivalCharge->entry()->where('festival_edition_id', $festivalEdition->id)->exists(), 404);
        abort_unless($request->user()?->can('manageFestivalFinance', $account), 403);
        $this->checkAttempt($festivalCharge, $reconcile);

        return back()->with('status', __('app.festival_payment_checked'));
    }

    private function authorizePortal(Request $request, FestivalEntry $entry, FestivalCharge $charge): Account
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $portalUser instanceof FestivalPortalUser
            && $portalUser->account_id === $account->id
            && $entry->account_id === $account->id && $entry->festival_portal_user_id === $portalUser->id
            && $charge->account_id === $account->id && $charge->festival_entry_id === $entry->id, 404);

        return $account;
    }

    private function checkAttempt(FestivalCharge $charge, ReconcileFestivalPaymentAttempt $reconcile): FestivalPaymentAttempt
    {
        $attempt = $charge->allocatedPaymentAttempts()->sortByDesc('id')->first();
        if (! $attempt || ! $reconcile->supports($attempt)) {
            throw ValidationException::withMessages(['provider' => __('app.festival_payment_check_unavailable')]);
        }

        try {
            return $reconcile->execute($attempt);
        } catch (PaymentGatewayException|InvalidPaymentCallbackException) {
            throw ValidationException::withMessages(['provider' => __('app.festival_payment_check_unavailable')]);
        }
    }

    private function paymentResponse(Account $account, FestivalEntry $entry, FestivalCharge $charge, ?string $message = null): JsonResponse
    {
        $entry->refresh();
        $charge->refresh()->load('entryStep');
        $step = $charge->entryStep;
        $groups = $step ? app(FestivalChargePaymentGroups::class)->forStep($step) : collect();
        $group = $groups->first(fn (array $group): bool => $group['charges']->contains('id', $charge->id));
        $state = app(FestivalEntryWorkflowState::class)->forEntry($entry)->first(fn (array $state): bool => $state['step']->id === $step?->id);

        return response()->json([
            'state' => ($group['status'] ?? $charge->status)->value.':'.$entry->status->value.':'.($charge->allocatedPaymentAttempts()->sortByDesc('id')->first()?->gateway_status ?? ''),
            'message' => $message,
            'payment_html' => $state ? view('festivals.portal._payment-fragment', [
                'account' => $account,
                'entry' => $entry,
                'selectedState' => $state,
                'paymentGroups' => $groups,
                'providers' => app(PaymentGatewayRegistry::class)->availableSettingsFor($account),
            ])->render() : null,
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
