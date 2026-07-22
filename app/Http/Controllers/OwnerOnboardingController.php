<?php

namespace App\Http\Controllers;

use App\Actions\BeginOwnerOnboarding;
use App\Actions\PublishOwnerOnboarding;
use App\Enums\AccountRole;
use App\Http\Requests\UpdateOwnerOnboardingStepRequest;
use App\Models\AccountOnboarding;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\Onboarding\OwnerPhoneOtpService;
use App\Support\Onboarding\PublicOwnerOnboardingAvailability;
use App\Support\Pwa\StudioPwaIconGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class OwnerOnboardingController extends Controller
{
    public function show(
        Request $request,
        PublicOwnerOnboardingAvailability $availability,
        OwnerPhoneOtpService $otpService,
        int $step = 1,
    ): View|RedirectResponse {
        $user = $this->owner($request);
        $onboarding = $this->onboardingFor($user);

        if ($onboarding?->isComplete()) {
            return redirect()->route('onboarding.success');
        }

        if (! $onboarding && $user->accounts()->exists()) {
            return redirect()->route('dashboard.index');
        }

        if ($step < AccountOnboarding::FirstStep || $step > AccountOnboarding::LastStep) {
            abort(404);
        }

        $currentStep = $onboarding?->current_step ?? AccountOnboarding::FirstStep;

        if ($step > $currentStep) {
            return redirect()->route('onboarding.show', ['step' => $currentStep]);
        }

        $account = $onboarding?->account;
        $answers = $onboarding?->stepAnswers($step) ?? [];
        $stepOneAnswers = $onboarding?->stepAnswers(1) ?? [];
        $stepTwoAnswers = $onboarding?->stepAnswers(2) ?? [];
        $defaults = $this->defaultsForStep($step, $user, $account?->name, $stepOneAnswers, $stepTwoAnswers);
        $activeChallenge = $step === 6 ? $otpService->activeChallengeFor($user, $user->phone) : null;
        $resendSeconds = $activeChallenge?->resend_available_at?->isFuture()
            ? $activeChallenge->resend_available_at->diffInSeconds(now())
            : 0;
        $trialEndsAt = $account?->subscription?->trial_ends_at;
        $priceVersion = $step === 1 && ! $trialEndsAt ? $availability->currentPriceVersion() : null;

        if (! $trialEndsAt && $priceVersion) {
            $trialEndsAt = now()->addDays($priceVersion->trial_days);
        }

        return view('owner-onboarding.step', [
            'step' => $step,
            'currentStep' => $currentStep,
            'onboarding' => $onboarding,
            'account' => $account,
            'values' => array_replace($defaults, $answers),
            'allStepAnswers' => collect(range(1, 5))->mapWithKeys(fn (int $answerStep): array => [
                $answerStep => $onboarding?->stepAnswers($answerStep) ?? [],
            ])->all(),
            'trialEndsAt' => $trialEndsAt,
            'turnstileSiteKey' => $availability->turnstileSiteKey(),
            'otpSent' => $activeChallenge !== null,
            'otpResendSeconds' => $resendSeconds,
        ]);
    }

    public function store(
        UpdateOwnerOnboardingStepRequest $request,
        BeginOwnerOnboarding $beginOnboarding,
        int $step,
    ): RedirectResponse {
        abort_if($step === AccountOnboarding::LastStep, 404);

        $user = $this->owner($request);
        $onboarding = $this->onboardingFor($user);
        $currentStep = $onboarding?->current_step ?? AccountOnboarding::FirstStep;

        if ($step > $currentStep) {
            return redirect()->route('onboarding.show', ['step' => $currentStep]);
        }

        $validated = $request->validated();

        if ($step === 1) {
            $stepAnswers = Arr::only($validated, ['studio_stage', 'location_count']);
            $onboarding = $onboarding
                ? $beginOnboarding->update(
                    $onboarding,
                    $user,
                    $validated['studio_name'],
                    $stepAnswers,
                    $request->file('logo'),
                )
                : $beginOnboarding->execute(
                    $user,
                    $validated['studio_name'],
                    $stepAnswers,
                    $request->file('logo'),
                );
        } else {
            if (! $onboarding) {
                return redirect()->route('onboarding.show', ['step' => 1]);
            }

            $onboarding->saveStep($step, $validated);
        }

        return redirect()
            ->route('onboarding.show', ['step' => min(AccountOnboarding::LastStep, $step + 1)])
            ->with('status', __('app.onboarding.progress_saved'));
    }

    public function publish(
        Request $request,
        PublishOwnerOnboarding $publishOnboarding,
        StudioPwaIconGenerator $pwaAssets,
    ): RedirectResponse {
        $user = $this->owner($request);
        $onboarding = $this->onboardingFor($user);

        abort_unless($onboarding, 404);

        $published = $publishOnboarding->execute($onboarding, $user);
        $pwaAssets->ensure($published->account);

        return redirect()->route('onboarding.success');
    }

    public function success(Request $request): View|RedirectResponse
    {
        $user = $this->owner($request);
        $onboarding = $this->onboardingFor($user);

        if (! $onboarding?->isComplete()) {
            return redirect()->route('onboarding.show', [
                'step' => $onboarding?->current_step ?? AccountOnboarding::FirstStep,
            ]);
        }

        $account = $onboarding->account;
        $location = $account->locations()->active()->oldest('id')->firstOrFail();
        $scheduledClass = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->publicUpcoming()
            ->with(['classType', 'trainer', 'room'])
            ->oldest('starts_at')
            ->first();

        return view('owner-onboarding.success', [
            'onboarding' => $onboarding,
            'account' => $account,
            'location' => $location,
            'scheduledClass' => $scheduledClass,
            'scheduleUrl' => route('public.schedule', [$account->slug, $location->slug]),
            'scheduleEmbedUrl' => route('public.schedule.embed', [$account->slug, $location->slug]),
        ]);
    }

    public function trackShare(Request $request): Response
    {
        $onboarding = $this->onboardingFor($this->owner($request));

        abort_unless($onboarding?->isComplete(), 404);
        $onboarding->recordMetric('public_link_shared_at');

        return response()->noContent();
    }

    private function owner(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && ! $user->isPlatformAdmin(), 404);

        return $user;
    }

    private function onboardingFor(User $user): ?AccountOnboarding
    {
        return AccountOnboarding::query()
            ->whereHas('account.memberships', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('role', AccountRole::Owner->value))
            ->with('account.subscription')
            ->latest()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $stepOneAnswers
     * @param  array<string, mixed>  $stepTwoAnswers
     * @return array<string, mixed>
     */
    private function defaultsForStep(int $step, User $user, ?string $studioName, array $stepOneAnswers, array $stepTwoAnswers): array
    {
        $preparing = ($stepOneAnswers['studio_stage'] ?? null) === 'preparing';
        $firstDate = now('Europe/Kyiv')->addDays($preparing ? 7 : 1)->startOfDay();

        return match ($step) {
            1 => [
                'studio_stage' => 'operating',
                'studio_name' => $studioName ?? '',
                'location_count' => 1,
            ],
            2 => [
                'location_name' => $studioName ?? '',
                'address' => '',
                'room_name' => __('app.onboarding.default_room_name'),
                'capacity' => 10,
            ],
            3 => [
                'teaching_mode' => 'owner',
                'trainer_name' => $user->name,
            ],
            4 => [
                'direction_name' => '',
                'class_name' => '',
                'duration_minutes' => 60,
                'capacity' => $stepTwoAnswers['capacity'] ?? 10,
            ],
            5 => [
                'weekday' => $firstDate->isoWeekday(),
                'start_time' => '18:00',
                'start_date' => $firstDate->toDateString(),
            ],
            default => [],
        };
    }
}
