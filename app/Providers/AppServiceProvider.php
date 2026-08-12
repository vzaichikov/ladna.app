<?php

namespace App\Providers;

use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\Location;
use App\Policies\AccountPolicy;
use App\Policies\FestivalEditionPolicy;
use App\Policies\LocationPolicy;
use App\Support\ApplicationVersion;
use App\Support\Mail\LadnaTransactionalTransport;
use App\Support\Mail\MailDeliveryTransportResolver;
use App\Support\SystemAppearance;
use App\Support\WorkingLocationContext;
use App\View\Composers\AppBreadcrumbComposer;
use App\View\Composers\FestivalWorkspaceComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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
        $this->app->scoped(WorkingLocationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('ladna_transactional', fn (array $config = []): LadnaTransactionalTransport => new LadnaTransactionalTransport(
            $this->app->make(MailDeliveryTransportResolver::class),
        ));

        Carbon::setLocale(app()->getLocale());

        Model::preventLazyLoading(! app()->isProduction());

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(FestivalEdition::class, FestivalEditionPolicy::class);
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
        Gate::define('recordCustomerPayments', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::RecordCustomerPayments));
        Gate::define('manageStudioCashflow', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioCashflow));
        Gate::define('viewStudioFinancialReports', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ViewStudioFinancialReports));
        Gate::define('manageStudioPayroll', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioPayroll));
        Gate::define('viewActivityLog', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ViewActivityLog));
        Gate::define('markAttendance', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::MarkAttendance));
        Gate::define('manageTrainers', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageTrainers));
        Gate::define('manageStudioSettings', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioSettings));
        Gate::define('manageEvents', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageEvents));
        Gate::define('checkInEventTickets', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::CheckInEventTickets));
        Gate::define('manageFestivals', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivals));
        Gate::define('manageFestivalRegistrations', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivalRegistrations));
        Gate::define('manageFestivalSchedule', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivalSchedule));
        Gate::define('manageFestivalFinance', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivalFinance));
        Gate::define('judgeFestivals', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::JudgeFestivals));
        Gate::define('checkInFestivalTickets', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::CheckInFestivalTickets));

        Password::defaults(fn (): Password => Password::min(6));

        View::composer(['layouts.app', 'layouts.public'], function (ViewInstance $view): void {
            $view
                ->with('systemAppearance', SystemAppearance::current())
                ->with('applicationVersion', ApplicationVersion::current())
                ->with('applicationRevision', ApplicationVersion::revision());

            if ($view->name() !== 'layouts.app') {
                return;
            }

            $account = $view->getData()['account'] ?? null;

            if (! $account instanceof Account || ! $account->exists) {
                return;
            }

            $workingLocationContext = $this->app->make(WorkingLocationContext::class);

            $view
                ->with('workingLocations', $workingLocationContext->locations($account))
                ->with('workingLocation', $workingLocationContext->location($account))
                ->with('workingLocationValue', $workingLocationContext->value($account));
        });
        View::composer('layouts.app', AppBreadcrumbComposer::class);
        View::composer('layouts.app', FestivalWorkspaceComposer::class);

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

        RateLimiter::for('event-checkout', function (Request $request): Limit {
            return Limit::perMinute(8)->by($request->string('buyer_email')->lower().'|'.$request->ip());
        });

        RateLimiter::for('event-scanner', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->user()?->id.'|'.$request->ip());
        });

        RateLimiter::for('festival-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->string('email')->lower().$request->string('phone').'|'.$request->route('accountSlug').'|'.$request->ip());
        });

        RateLimiter::for('festival-otp', function (Request $request): Limit {
            return Limit::perMinute(3)->by($request->string('phone').'|'.$request->route('accountSlug').'|'.$request->ip());
        });

        RateLimiter::for('festival-checkout', function (Request $request): Limit {
            return Limit::perMinute(8)->by($request->string('buyer_email')->lower().'|'.$request->ip());
        });

        RateLimiter::for('festival-scanner', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->user()?->id.'|'.$request->ip());
        });

        RateLimiter::for('mobile-auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->string('email')->lower().$request->string('phone').$request->ip());
        });

        RateLimiter::for('mobile-api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip());
        });
    }
}
