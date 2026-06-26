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
        Gate::define('markAttendance', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::MarkAttendance));
        Gate::define('manageTrainers', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageTrainers));
        Gate::define('manageStudioSettings', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioSettings));

        Password::defaults(fn (): Password => Password::min(6));

        View::composer(['layouts.app', 'layouts.public'], function (ViewInstance $view): void {
            $view
                ->with('systemAppearance', SystemAppearance::current())
                ->with('applicationVersion', ApplicationVersion::current());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->string('email')->lower().'|'.$request->ip());
        });

        RateLimiter::for('demo-signup', function (Request $request): Limit {
            return Limit::perMinute(3)->by($request->string('owner_email')->lower().'|'.$request->ip());
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
    }
}
