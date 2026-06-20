<?php

namespace App\Providers;

use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\Location;
use App\Policies\AccountPolicy;
use App\Policies\LocationPolicy;
use App\Support\SystemAppearance;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        Gate::define('markAttendance', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::MarkAttendance));
        Gate::define('manageTrainers', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageTrainers));
        Gate::define('manageStudioSettings', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioSettings));

        View::composer(['layouts.app', 'layouts.public'], function ($view): void {
            $view->with('systemAppearance', SystemAppearance::current());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->string('email')->lower().'|'.$request->ip());
        });
    }
}
