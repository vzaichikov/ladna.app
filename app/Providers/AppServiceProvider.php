<?php

namespace App\Providers;

use App\Enums\AccountRole;
use App\Enums\FestivalPortalRole;
use App\Enums\StudioPermission;
use App\Http\Controllers\McpOAuthApprovalController;
use App\Http\Controllers\McpOAuthDenialController;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\Location;
use App\Policies\AccountPolicy;
use App\Policies\FestivalEditionPolicy;
use App\Policies\LocationPolicy;
use App\Support\ApplicationVersion;
use App\Support\Mail\LadnaTransactionalTransport;
use App\Support\Mail\MailDeliveryTransportResolver;
use App\Support\Mcp\McpOAuthAuthorization;
use App\Support\SystemAppearance;
use App\Support\WorkingLocationContext;
use App\View\Composers\AppBreadcrumbComposer;
use App\View\Composers\FestivalWorkspaceComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passport::ignoreRoutes();
        $this->app->scoped(WorkingLocationContext::class);
        $this->app->bind(ApproveAuthorizationController::class, McpOAuthApprovalController::class);
        $this->app->bind(DenyAuthorizationController::class, McpOAuthDenialController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::tokensCan([
            'mcp:use' => 'Use the connected Ladna studio',
            'offline_access' => 'Keep the Ladna connection signed in',
        ]);
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(90));
        Passport::authorizationView(function (array $parameters) {
            $request = $parameters['request'];
            $account = app(McpOAuthAuthorization::class)->remember(
                $request,
                $parameters['user'],
                $parameters['client'],
                $parameters['authToken'],
            );

            return view('mcp.authorize', [...$parameters, 'account' => $account]);
        });

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
        Gate::define('manageEventFestivalStaff', function ($user, Account $account): bool {
            $membership = $account->membershipFor($user);

            return $membership !== null
                && in_array($membership->role, [AccountRole::Owner, AccountRole::Admin], true);
        });
        Gate::define('manageStudioSettings', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageStudioSettings));
        Gate::define('manageEvents', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageEvents));
        Gate::define('checkInEventTickets', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::CheckInEventTickets));
        Gate::define('manageFestivals', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivals));
        Gate::define('manageFestivalRegistrations', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivalRegistrations));
        Gate::define('manageFestivalSchedule', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivalSchedule));
        Gate::define('manageFestivalFinance', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::ManageFestivalFinance));
        Gate::define('judgeFestivals', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::JudgeFestivals));
        Gate::define('checkInFestivalTickets', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::CheckInFestivalTickets));
        Gate::define('doorStaff', fn ($user, Account $account): bool => $account->userCan($user, StudioPermission::DoorStaff));

        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $request->attributes->get('accountApiToken')?->id ?: $request->ip(),
        ));
        RateLimiter::for('festival-battles-api', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $request->attributes->get('accountApiToken')?->id ?: $request->ip(),
        ));
        RateLimiter::for('mcp-oauth', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $request->attributes->get('mcpOAuthConnection')?->id ?: $request->ip(),
        ));
        RateLimiter::for('mcp-oauth-register', fn (Request $request): Limit => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('mcp-oauth-token', fn (Request $request): Limit => Limit::perMinute(30)->by(
            $request->string('client_id')->toString().'|'.$request->ip(),
        ));

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
        View::composer('festivals.portal._nav', function (ViewInstance $view): void {
            $account = $view->getData()['account'] ?? null;
            $portalUser = $view->getData()['portalUser'] ?? request()->user('festival');
            $portalEntryCount = 0;
            $festivalTelegramBotLinks = collect();

            if ($account instanceof Account
                && $portalUser instanceof FestivalPortalUser
                && $portalUser->role === FestivalPortalRole::Registrant
                && $portalUser->account_id === $account->id) {
                $portalEntryCount = $portalUser->entries()
                    ->where('account_id', $account->id)
                    ->count();

                $activeTelegramInstallation = static fn (Builder|Relation $query): Builder|Relation => $query
                    ->where('is_enabled', true)
                    ->whereNotNull('bot_username')
                    ->where('bot_username', '!=', '');

                $festivalTelegramBotLinks = $account->festivalSeries()
                    ->where('is_active', true)
                    ->whereHas('telegramBotInstallation', $activeTelegramInstallation)
                    ->with(['telegramBotInstallation' => $activeTelegramInstallation])
                    ->orderBy('name')
                    ->get()
                    ->map(static function (FestivalSeries $series): array {
                        $username = ltrim((string) $series->telegramBotInstallation?->bot_username, '@');

                        return [
                            'series_name' => $series->name,
                            'url' => 'https://t.me/'.$username,
                        ];
                    })
                    ->values();
            }

            $view
                ->with('portalEntryCount', $portalEntryCount)
                ->with('festivalTelegramBotLinks', $festivalTelegramBotLinks);
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

        RateLimiter::for('festival-profile-otp', function (Request $request): Limit {
            return Limit::perMinute(3)->by((string) $request->user('festival')?->getAuthIdentifier().'|'.$request->route('accountSlug').'|'.$request->ip());
        });

        RateLimiter::for('festival-checkout', function (Request $request): Limit {
            return Limit::perMinute(8)->by($request->string('buyer_email')->lower().'|'.$request->ip());
        });

        RateLimiter::for('festival-scanner', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->user()?->id.'|'.$request->ip());
        });

        RateLimiter::for('festival-stream-bootstrap', function (Request $request): Limit {
            if ($request->routeIs('festival.stream.bootstrap')) {
                return Limit::perMinute(120)->by($request->ip());
            }

            if ($request->routeIs('public.festival-orders.stream.watch')) {
                return Limit::perMinute(10)->by(hash('sha256', (string) $request->route('accessToken')).'|'.$request->ip());
            }

            $userId = $request->routeIs('dashboard.accounts.festivals.online-stream.preview')
                ? $request->user()?->getAuthIdentifier()
                : $request->user('festival')?->getAuthIdentifier();

            return Limit::perMinute(10)->by((string) $userId.'|'.$request->ip());
        });

        RateLimiter::for('festival-stream-status', function (Request $request): Limit {
            $edition = $request->route('festivalEdition');
            $editionKey = $edition instanceof FestivalEdition ? $edition->getKey() : $edition;

            return Limit::perMinute(12)->by((string) $request->user()?->getAuthIdentifier().'|'.$editionKey.'|'.$request->ip());
        });

        RateLimiter::for('festival-stream-heartbeat', function (Request $request): Limit {
            return Limit::perMinute(10)->by(hash('sha256', implode('|', [
                $request->ip(),
                (string) $request->route('path'),
                (string) $request->header('Cookie'),
            ])));
        });

        RateLimiter::for('festival-stream-gateway', function (Request $request): Limit {
            return Limit::perMinute(120)->by(hash('sha256', implode('|', [
                (string) $request->header('X-Original-Client-IP', $request->ip()),
                (string) $request->header('X-Festival-Stream-Path'),
                (string) $request->header('Cookie'),
            ])));
        });

        RateLimiter::for('festival-stream-publisher', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('mobile-auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->string('email')->lower().$request->string('phone').$request->ip());
        });

        RateLimiter::for('mobile-api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip());
        });
    }
}
