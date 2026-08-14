<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SyncFestivalProfileParticipant;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalStreamProvider;
use App\Http\Requests\FestivalOtpVerifyRequest;
use App\Http\Requests\FestivalPortalProfileRequest;
use App\Http\Requests\FestivalProfilePhoneOtpSendRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\Festivals\FestivalMediaMtxGateway;
use App\Support\Festivals\FestivalOtpService;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FestivalPortalController extends Controller
{
    public function dashboard(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $editions = FestivalEdition::query()->whereBelongsTo($account)->published()->with(['series', 'coverMedia'])->orderBy('starts_at')->get();

        return view('festivals.portal.dashboard', compact('account', 'portalUser', 'editions'));
    }

    public function entries(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $entries = $portalUser->entries()->with([
            'edition.coverMedia',
            'category',
            'steps',
            'scheduleSlots' => fn ($query) => $query
                ->whereNotNull('published_at')
                ->whereNotNull('starts_at')
                ->whereNotNull('ends_at')
                ->with('stage')
                ->orderBy('starts_at'),
        ])->latest()->get();
        $notifications = FestivalNotification::query()->where('festival_portal_user_id', $portalUser->id)->latest()->limit(50)->get();

        return view('festivals.portal.entries', compact('account', 'portalUser', 'entries', 'notifications'));
    }

    public function judgeDashboard(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($portalUser->role === FestivalPortalRole::Judge, 403);
        $assignments = $portalUser->judgeAssignments()
            ->where('is_active', true)
            ->with(['edition.series', 'categories'])
            ->whereHas('edition', fn ($query) => $query->where('account_id', $account->id))
            ->latest('id')
            ->get();

        return view('festivals.portal.judge-dashboard', compact('account', 'portalUser', 'assignments'));
    }

    public function guestDashboard(Request $request, string $accountSlug, PaymentGatewayRegistry $gateways, FestivalMediaMtxGateway $mediaMtx): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($portalUser->role === FestivalPortalRole::Guest, 403);
        $orders = $portalUser->ticketOrders()
            ->whereBelongsTo($account)
            ->with(['edition.coverMedia', 'items.admissionType.onlineStream', 'tickets.admissionType', 'tickets.streamEntitlement.stream'])
            ->latest()
            ->get();
        $editions = FestivalEdition::query()
            ->whereBelongsTo($account)
            ->published()
            ->whereHas('admissionTypes', fn ($query) => $query->availableForSale())
            ->with(['admissionTypes' => fn ($query) => $query->availableForSale()->with('onlineStream')])
            ->orderBy('starts_at')
            ->get();
        $providers = $gateways->availableSettingsFor($account);
        $streamStatuses = $orders->flatMap->tickets
            ->pluck('streamEntitlement.stream')
            ->filter()
            ->unique('id')
            ->mapWithKeys(function ($stream) use ($mediaMtx): array {
                if ($stream->provider === FestivalStreamProvider::YouTube) {
                    return [$stream->id => ['provider' => FestivalStreamProvider::YouTube->value]];
                }

                try {
                    return [$stream->id => $mediaMtx->status($stream)];
                } catch (Throwable $exception) {
                    report($exception);

                    return [$stream->id => null];
                }
            });

        return view('festivals.portal.guest-dashboard', compact('account', 'portalUser', 'orders', 'editions', 'providers', 'streamStatuses'));
    }

    public function editProfile(Request $request, string $accountSlug, CustomerAuthAvailability $availability): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $portalUser->loadMissing('profileParticipant');
        $profilePhoneVerification = $this->profilePhoneVerificationState($account, $portalUser);
        $phoneVerificationAvailable = $portalUser->role === FestivalPortalRole::Registrant
            && $availability->methodsFor($account)->otp;

        if ($phoneVerificationAvailable && $profilePhoneVerification) {
            return view('festivals.portal.phone-verification', compact('account', 'portalUser', 'profilePhoneVerification'));
        }

        $isParticipantProfileCompletion = $portalUser->role === FestivalPortalRole::Registrant
            && ! $portalUser->profileIsComplete($phoneVerificationAvailable);

        return view('festivals.portal.profile', compact('account', 'portalUser', 'isParticipantProfileCompletion') + [
            'profilePhoneVerification' => $portalUser->role === FestivalPortalRole::Registrant ? null : $profilePhoneVerification,
            'participantProfileStep' => $isParticipantProfileCompletion
                ? ['current' => $phoneVerificationAvailable ? 3 : 2, 'total' => $phoneVerificationAvailable ? 3 : 2]
                : null,
        ]);
    }

    public function updateProfile(
        FestivalPortalProfileRequest $request,
        string $accountSlug,
        SyncFestivalProfileParticipant $syncParticipant,
        CustomerAuthAvailability $availability,
    ): RedirectResponse {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $data = $request->validated();
        Arr::pull($data, 'profile_action');
        $dateOfBirth = Arr::pull($data, 'date_of_birth');
        $phoneVerificationAvailable = $portalUser->role === FestivalPortalRole::Registrant
            && $availability->methodsFor($account)->otp;

        if ($phoneVerificationAvailable && $portalUser->phone_verified_at === null) {
            return redirect()->route('festival.portal.profile.edit', $account->slug)
                ->with('status', __('app.festival_phone_verification_first'));
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($data['email_normalized'] !== $portalUser->email_normalized) {
            $data['email_verified_at'] = null;
        }

        $pendingPhone = null;
        if (($portalUser->role !== FestivalPortalRole::Registrant || $phoneVerificationAvailable)
            && filled($data['phone_normalized'])
            && ($data['phone_normalized'] !== $portalUser->phone_normalized || $portalUser->phone_verified_at === null)) {
            $pendingPhone = $data['phone_normalized'];
            unset($data['phone'], $data['phone_normalized'], $data['phone_verified_at']);
        } elseif (filled($data['phone_normalized']) && $data['phone_normalized'] !== $portalUser->phone_normalized) {
            $data['phone_verified_at'] = null;
        }

        DB::transaction(function () use ($portalUser, $data, $dateOfBirth, $syncParticipant): void {
            $lockedPortalUser = FestivalPortalUser::query()->whereKey($portalUser)->lockForUpdate()->firstOrFail();
            $lockedPortalUser->update($data);
            $syncParticipant->execute($lockedPortalUser, $dateOfBirth);
        }, 3);

        if ($pendingPhone) {
            $request->session()->put($this->profilePhoneSessionKey($account, $portalUser), $pendingPhone);
            $request->session()->forget($this->profilePhoneChallengeSessionKey($account, $portalUser));
        } else {
            $request->session()->forget([
                $this->profilePhoneSessionKey($account, $portalUser),
                $this->profilePhoneChallengeSessionKey($account, $portalUser),
            ]);
        }

        $portalUser->refresh();
        $request->session()->put('locale', $portalUser->locale);

        $route = $pendingPhone || ! $portalUser->profileIsComplete($phoneVerificationAvailable)
            ? $this->profileEditRoute($portalUser)
            : $this->dashboardRoute($portalUser);

        return redirect()->route($route, $account->slug)->with('status', __('app.festival_profile_saved'));
    }

    public function sendProfilePhoneOtp(
        FestivalProfilePhoneOtpSendRequest $request,
        string $accountSlug,
        FestivalOtpService $otp,
        CustomerAuthAvailability $availability,
    ): RedirectResponse {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $phone = $portalUser->role === FestivalPortalRole::Registrant
            ? $request->validated('phone')
            : $this->pendingProfilePhone($account, $portalUser);

        if ($portalUser->role === FestivalPortalRole::Registrant) {
            abort_unless($availability->methodsFor($account)->otp, 404);
            $request->session()->put($this->profilePhoneSessionKey($account, $portalUser), $phone);
            $request->session()->forget($this->profilePhoneChallengeSessionKey($account, $portalUser));
        }

        if (! $phone) {
            return redirect()->route($this->profileEditRoute($portalUser), $account->slug);
        }

        return $this->sendPendingProfilePhoneOtp($request, $account, $portalUser, $phone, $otp);
    }

    public function resendProfilePhoneOtp(
        Request $request,
        string $accountSlug,
        FestivalOtpService $otp,
        CustomerAuthAvailability $availability,
    ): RedirectResponse {
        [$account, $portalUser] = $this->context($request, $accountSlug);

        if ($portalUser->role === FestivalPortalRole::Registrant) {
            abort_unless($availability->methodsFor($account)->otp, 404);
        }

        $phone = $this->pendingProfilePhone($account, $portalUser);

        if (! $phone) {
            return redirect()->route($this->profileEditRoute($portalUser), $account->slug);
        }

        return $this->sendPendingProfilePhoneOtp($request, $account, $portalUser, $phone, $otp);
    }

    public function changeProfilePhone(Request $request, string $accountSlug): RedirectResponse
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $request->session()->forget([
            $this->profilePhoneSessionKey($account, $portalUser),
            $this->profilePhoneChallengeSessionKey($account, $portalUser),
        ]);

        return redirect()->route($this->profileEditRoute($portalUser), $account->slug);
    }

    public function verifyProfilePhoneOtp(FestivalOtpVerifyRequest $request, string $accountSlug, FestivalOtpService $otp): RedirectResponse
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $phone = $this->pendingProfilePhone($account, $portalUser);

        if (! $phone || $phone !== $request->validated('phone')) {
            throw ValidationException::withMessages(['code' => __('app.customer_otp_invalid')]);
        }

        DB::transaction(function () use ($account, $portalUser, $phone, $request, $otp): void {
            $lockedPortalUser = FestivalPortalUser::query()->whereKey($portalUser)->lockForUpdate()->firstOrFail();
            if (FestivalPortalUser::query()->whereBelongsTo($account)->forRole($lockedPortalUser->role)->where('phone_normalized', $phone)->whereKeyNot($lockedPortalUser)->exists()) {
                throw ValidationException::withMessages(['phone' => __('validation.unique', ['attribute' => __('app.phone')])]);
            }

            $result = $otp->verify($account, $lockedPortalUser->role, $phone, $request->validated('code'));
            if (! $result->ok || ! $result->challenge) {
                throw ValidationException::withMessages(['code' => $result->message ?? __('app.customer_otp_invalid')]);
            }

            $lockedPortalUser->forceFill([
                'phone' => $result->challenge->phone,
                'phone_normalized' => $result->challenge->phone,
                'phone_verified_at' => now(),
            ])->save();
        }, 3);

        $request->session()->forget([
            $this->profilePhoneSessionKey($account, $portalUser),
            $this->profilePhoneChallengeSessionKey($account, $portalUser),
        ]);
        $portalUser->refresh();
        $route = $portalUser->profileIsComplete()
            ? $this->dashboardRoute($portalUser)
            : $this->profileEditRoute($portalUser);

        return redirect()->route($route, $account->slug)->with('status', __('app.customer_profile_phone_verified'));
    }

    /** @return array{Account, FestivalPortalUser} */
    private function context(Request $request, string $slug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $slug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id, 404);

        return [$account, $portalUser];
    }

    /** @return array{phone: string, challenge_active: bool}|null */
    private function profilePhoneVerificationState(Account $account, FestivalPortalUser $portalUser): ?array
    {
        $phone = $this->pendingProfilePhone($account, $portalUser);

        if (! $phone && ! ($portalUser->role === FestivalPortalRole::Registrant && $portalUser->phone_verified_at === null)) {
            return null;
        }

        return [
            'phone' => $phone ?? '',
            'challenge_active' => filled($phone) && (bool) session($this->profilePhoneChallengeSessionKey($account, $portalUser)),
        ];
    }

    private function pendingProfilePhone(Account $account, FestivalPortalUser $portalUser): ?string
    {
        $phone = session($this->profilePhoneSessionKey($account, $portalUser));

        if (is_string($phone) && $phone !== '') {
            return $phone;
        }

        if ($portalUser->role === FestivalPortalRole::Registrant && $portalUser->phone_verified_at === null) {
            return filled($portalUser->phone_normalized) ? $portalUser->phone_normalized : null;
        }

        return null;
    }

    private function sendPendingProfilePhoneOtp(
        Request $request,
        Account $account,
        FestivalPortalUser $portalUser,
        string $phone,
        FestivalOtpService $otp,
    ): RedirectResponse {
        $result = $otp->send($account, $portalUser->role, $phone, (string) $request->ip(), substr((string) $request->userAgent(), 0, 1000));

        if (! $result->ok) {
            return redirect()
                ->route($this->profileEditRoute($portalUser), $account->slug)
                ->withErrors(['phone' => $result->message ?? __('app.customer_otp_send_failed')])
                ->with('otp_resend_seconds', $result->secondsUntilResend);
        }

        $request->session()->put($this->profilePhoneChallengeSessionKey($account, $portalUser), true);

        return redirect()
            ->route($this->profileEditRoute($portalUser), $account->slug)
            ->with('status', __('app.customer_otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend);
    }

    private function profilePhoneSessionKey(Account $account, FestivalPortalUser $portalUser): string
    {
        return 'festival_profile_phone_'.$account->id.'_'.$portalUser->id;
    }

    private function profilePhoneChallengeSessionKey(Account $account, FestivalPortalUser $portalUser): string
    {
        return 'festival_profile_phone_challenge_'.$account->id.'_'.$portalUser->id;
    }

    private function profileEditRoute(FestivalPortalUser $portalUser): string
    {
        return match ($portalUser->role) {
            FestivalPortalRole::Registrant => 'festival.portal.profile.edit',
            FestivalPortalRole::Judge => 'festival.portal.judge.profile.edit',
            FestivalPortalRole::Guest => 'festival.portal.guest.profile.edit',
        };
    }

    private function dashboardRoute(FestivalPortalUser $portalUser): string
    {
        return match ($portalUser->role) {
            FestivalPortalRole::Registrant => 'festival.portal.dashboard',
            FestivalPortalRole::Judge => 'festival.portal.judge.dashboard',
            FestivalPortalRole::Guest => 'festival.portal.guest.dashboard',
        };
    }
}
