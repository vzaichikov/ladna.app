<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Location;
use App\Policies\AccountPolicy;
use App\Policies\LocationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Model::preventLazyLoading(! app()->isProduction());

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Location::class, LocationPolicy::class);
        Gate::define('accessPlatform', fn ($user): bool => $user->isPlatformAdmin());

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->string('email')->lower().'|'.$request->ip());
        });

    }
}
