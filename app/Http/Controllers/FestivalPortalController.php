<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SyncFestivalProfileParticipant;
use App\Enums\FestivalPortalRole;
use App\Http\Requests\FestivalOtpVerifyRequest;
use App\Http\Requests\FestivalPortalProfileRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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
            'scheduleSlots' => fn ($query) => $query->whereNotNull('published_at')->with('stage')->orderBy('starts_at'),
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

    public function editProfile(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $portalUser->loadMissing('profileParticipant');

        return view('festivals.portal.profile', compact('account', 'portalUser') + [
            'profilePhoneVerification' => $this->profilePhoneVerificationState($account, $portalUser),
        ]);
    }

    public function updateProfile(
        FestivalPortalProfileRequest $request,
        string $accountSlug,
        SyncFestivalProfileParticipant $syncParticipant,
        FestivalOtpService $otp,
    ): RedirectResponse {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $data = $request->validated();
        $profileAction = Arr::pull($data, 'profile_action');
        $dateOfBirth = Arr::pull($data, 'date_of_birth');

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($data['email_normalized'] !== $portalUser->email_normalized) {
            $data['email_verified_at'] = null;
        }

        $pendingPhone = null;
        if (filled($data['phone_normalized']) && ($data['phone_normalized'] !== $portalUser->phone_normalized || $portalUser->phone_verified_at === null)) {
            $pendingPhone = $data['phone_normalized'];
            unset($data['phone'], $data['phone_normalized'], $data['phone_verified_at']);
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

        if ($profileAction === 'send_phone_otp' && $pendingPhone) {
            return $this->sendPendingProfilePhoneOtp($request, $account, $portalUser, $pendingPhone, $otp, true);
        }

        $route = $pendingPhone || ! $portalUser->profileIsComplete()
            ? ($portalUser->role === FestivalPortalRole::Judge ? 'festival.portal.judge.profile.edit' : 'festival.portal.profile.edit')
            : ($portalUser->role === FestivalPortalRole::Judge ? 'festival.portal.judge.dashboard' : 'festival.portal.dashboard');

        return redirect()->route($route, $account->slug)->with('status', __('app.festival_profile_saved'));
    }

    public function sendProfilePhoneOtp(Request $request, string $accountSlug, FestivalOtpService $otp): RedirectResponse
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $phone = $this->pendingProfilePhone($account, $portalUser);

        if (! $phone) {
            return redirect()->route($this->profileEditRoute($portalUser), $account->slug);
        }

        return $this->sendPendingProfilePhoneOtp($request, $account, $portalUser, $phone, $otp);
    }

    public function resendProfilePhoneOtp(Request $request, string $accountSlug, FestivalOtpService $otp): RedirectResponse
    {
        return $this->sendProfilePhoneOtp($request, $accountSlug, $otp);
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
            if (FestivalPortalUser::query()->whereBelongsTo($account)->where('phone_normalized', $phone)->whereKeyNot($lockedPortalUser)->exists()) {
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
            ? ($portalUser->role === FestivalPortalRole::Judge ? 'festival.portal.judge.dashboard' : 'festival.portal.dashboard')
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
        bool $applyRateLimit = false,
    ): RedirectResponse {
        if ($applyRateLimit) {
            $rateLimitKey = md5('festival-profile-otp'.$portalUser->getAuthIdentifier().'|'.$account->slug.'|'.$request->ip());

            if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
                return redirect()
                    ->route($this->profileEditRoute($portalUser), $account->slug)
                    ->withErrors(['phone' => __('app.customer_otp_resend_wait', ['seconds' => RateLimiter::availableIn($rateLimitKey)])]);
            }

            RateLimiter::hit($rateLimitKey, 60);
        }

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
        return $portalUser->role === FestivalPortalRole::Judge ? 'festival.portal.judge.profile.edit' : 'festival.portal.profile.edit';
    }
}
