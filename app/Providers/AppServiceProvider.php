<?php

namespace App\Providers;

use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\Location;
use App\Policies\AccountPolicy;
use App\Policies\LocationPolicy;
use App\Support\ApplicationVersion;
use App\Support\SystemAppearance;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale(app()->getLocale());

        Model::preventLazyLoading(! app()->isProduction());

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Location::class, LocationPolicy::class);
        Gate::before(fn ($user): ?bool => $user->isPlatformAdmin() ? true : null);
        Gate::define('accessPlatform', fn ($user): bool => $user->isPlatformAdmin());
        Gate::define('manageSchedule', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageSchedule));
        Gate::define('manageClients', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageClients));
        Gate::define('manageBookings', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageBookings));
        Gate::define('manageWebsiteLeads', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageWebsiteLeads));
        Gate::define('interactWithTelegramBot', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::InteractWithTelegramBot));
        Gate::define('issueCustomerClassPasses', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::IssueCustomerClassPasses));
        Gate::define('manageCustomerClassPasses', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageCustomerClassPasses));
        Gate::define('correctClosedClasses', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::CorrectClosedClasses));
        Gate::define('manageStudioCashflow', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioCashflow));
        Gate::define('viewActivityLog', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ViewActivityLog));
        Gate::define('markAttendance', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::MarkAttendance));
        Gate::define('manageTrainers', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageTrainers));
        Gate::define('manageStudioSettings', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioSettings));

        Password::defaults(fn (): Password => Password::min(6));

        View::composer(['layouts.app', 'layouts.public'], function (ViewInstance $view): void {
            $view
                ->with('systemAppearance', SystemAppearance::current())
                ->with('applicationVersion', ApplicationVersion::current())
                ->with('applicationRevision', ApplicationVersion::revision());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->string('email')->lower().'|'.$request->ip());
        });

        RateLimiter::for('owner-registration', function (Request $request): array {
            return [
                Limit::perHour(10)->by('owner-registration-ip:'.$request->ip()),
                Limit::perHour(5)->by('owner-registration-email:'.$request->string('email')->lower().'|'.$request->ip()),
            ];
        });

        RateLimiter::for('owner-onboarding', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->user()?->id.'|'.$request->ip());
        });

        RateLimiter::for('owner-otp', function (Request $request): array {
            $userKey = (string) ($request->user()?->id ?? 'guest');
            $phoneKey = hash('sha256', preg_replace('/\D+/', '', $request->string('phone')->toString()).'|'.$request->ip());

            return [
                Limit::perMinute(3)->by('owner-otp-user-minute:'.$userKey),
                Limit::perHour(10)->by('owner-otp-user-hour:'.$userKey),
                Limit::perMinute(3)->by('owner-otp-phone-minute:'.$phoneKey),
                Limit::perHour(20)->by('owner-otp-ip-hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('owner-otp-verify', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->user()?->id.'|'.$request->ip());
        });

        RateLimiter::for('customer-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->string('email')->lower().$request->string('phone').'|'.$request->ip());
        });

        RateLimiter::for('customer-otp', function (Request $request): Limit {
            return Limit::perMinute(3)->by($request->string('phone').'|'.$request->ip());
        });

        RateLimiter::for('website-leads', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip());
        });

        RateLimiter::for('public-booking', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->string('customer_phone').$request->user('customer')?->getAuthIdentifier().'|'.$request->ip());
        });

        RateLimiter::for('mobile-auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->string('email')->lower().$request->string('phone').$request->ip());
        });

        RateLimiter::for('mobile-api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip());
        });
    }
}
