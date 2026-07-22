<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\AccountOnboarding;
use App\Models\User;
use App\Support\Onboarding\PublicOwnerOnboardingAvailability;
use App\Support\ReservedPublicSlugs;
use App\Support\SaasBilling\EnrollAccountInBilling;
use App\Support\SlugGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class BeginOwnerOnboarding
{
    public function __construct(
        private readonly PublicOwnerOnboardingAvailability $availability,
        private readonly EnrollAccountInBilling $enrollAccountInBilling,
    ) {}

    /**
     * @param  array<string, mixed>  $answers
     */
    public function execute(User $user, string $studioName, array $answers, ?UploadedFile $logo = null): AccountOnboarding
    {
        if ($user->accounts()->exists()) {
            throw ValidationException::withMessages([
                'studio_name' => __('app.onboarding.account_already_exists'),
            ]);
        }

        $priceVersion = $this->availability->currentPriceVersion();

        if (! $priceVersion || ! $this->availability->isAvailable()) {
            throw ValidationException::withMessages([
                'studio_name' => __('app.onboarding.registration_unavailable'),
            ]);
        }

        $storedLogoPath = null;

        try {
            return DB::transaction(function () use ($user, $studioName, $answers, $logo, $priceVersion, &$storedLogoPath): AccountOnboarding {
                $lockedUser = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedUser->accounts()->exists()) {
                    throw ValidationException::withMessages([
                        'studio_name' => __('app.onboarding.account_already_exists'),
                    ]);
                }

                $account = Account::create([
                    'name' => $studioName,
                    'slug' => $this->uniqueAccountSlug($studioName),
                    'default_language' => 'uk',
                    'country_code' => 'UA',
                    'default_currency' => 'UAH',
                    'timezone' => 'Europe/Kyiv',
                ]);

                if ($logo) {
                    $storedLogoPath = $logo->store('account-logos/'.$account->id, 'public');
                    $account->forceFill(['logo_path' => $storedLogoPath])->save();
                }

                $account->addOwner($lockedUser);

                $onboarding = $account->onboarding()->create([
                    'current_step' => 2,
                    'answers' => [],
                ]);
                $onboarding->saveStep(1, $answers);

                $this->enrollAccountInBilling->execute($account, $priceVersion);

                return $onboarding->load('account.subscription');
            });
        } catch (LogicException) {
            if ($storedLogoPath) {
                Storage::disk('public')->delete($storedLogoPath);
            }

            throw ValidationException::withMessages([
                'studio_name' => __('app.onboarding.trial_unavailable'),
            ]);
        } catch (Throwable $exception) {
            if ($storedLogoPath) {
                Storage::disk('public')->delete($storedLogoPath);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function update(AccountOnboarding $onboarding, User $user, string $studioName, array $answers, ?UploadedFile $logo = null): AccountOnboarding
    {
        $account = $onboarding->account;

        abort_unless($account->isOwnedBy($user), 404);

        $storedLogoPath = null;
        $previousLogoPath = $account->logo_path;

        try {
            $updated = DB::transaction(function () use ($onboarding, $studioName, $answers, $logo, &$storedLogoPath): AccountOnboarding {
                $lockedOnboarding = AccountOnboarding::query()
                    ->whereKey($onboarding->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $account = $lockedOnboarding->account;
                $account->forceFill([
                    'name' => $studioName,
                    'slug' => $this->uniqueAccountSlug($studioName, $account),
                ])->save();

                if ($logo) {
                    $storedLogoPath = $logo->store('account-logos/'.$account->id, 'public');
                    $account->forceFill(['logo_path' => $storedLogoPath])->save();
                }

                $lockedOnboarding->saveStep(1, $answers);

                return $lockedOnboarding->load('account.subscription');
            });
        } catch (Throwable $exception) {
            if ($storedLogoPath) {
                Storage::disk('public')->delete($storedLogoPath);
            }

            throw $exception;
        }

        if ($storedLogoPath && $previousLogoPath && ! str_starts_with($previousLogoPath, 'brand/')) {
            Storage::disk('public')->delete($previousLogoPath);
        }

        return $updated;
    }

    private function uniqueAccountSlug(string $source, ?Account $ignore = null): string
    {
        return SlugGenerator::unique(
            $source,
            'studio',
            fn (string $candidate): bool => Account::query()
                ->where('slug', $candidate)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
                ->exists(),
            ReservedPublicSlugs::all(),
        );
    }
}
