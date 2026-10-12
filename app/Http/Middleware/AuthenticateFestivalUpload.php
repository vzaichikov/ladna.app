<?php

namespace App\Http\Middleware;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFestivalUpload
{
    public function __construct(private readonly CustomerAuthAvailability $availability) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(filled(config('festival_uploads.url')) && $request->getHost() === parse_url(config('festival_uploads.url'), PHP_URL_HOST), 404);
        app()->setLocale(in_array($request->query('locale'), ['en', 'uk'], true) ? $request->query('locale') : 'uk');
        $account = $request->attributes->get('festivalAccount');
        $portalUser = FestivalPortalUser::query()->find($request->query('portal_user_id'));
        abort_unless($account instanceof Account && $portalUser instanceof FestivalPortalUser
            && $portalUser->account_id === $account->id && $portalUser->is_active
            && $portalUser->role === FestivalPortalRole::Registrant, 403, __('app.festival_upload_expired'));
        abort_unless($portalUser->profileIsComplete($this->availability->methodsFor($account)->otp), 403, __('app.festival_profile_required'));
        $request->setUserResolver(fn (?string $guard = null): ?FestivalPortalUser => $guard === 'festival' ? $portalUser : null);

        return $next($request);
    }
}
