<?php

namespace App\Actions\Festivals;

use App\Mail\FestivalPortalMail;
use App\Models\Account;
use App\Models\FestivalLoginToken;
use App\Models\FestivalPortalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FestivalMagicLink
{
    public function issue(Account $account, string $email, string $ip): void
    {
        $normalized = FestivalPortalUser::normalizeEmail($email);
        $rawToken = Str::random(64);

        $portalUser = DB::transaction(function () use ($account, $email, $normalized, $rawToken, $ip): FestivalPortalUser {
            $portalUser = FestivalPortalUser::query()->firstOrCreate(
                ['account_id' => $account->id, 'email_normalized' => $normalized],
                ['email' => trim($email), 'locale' => $account->default_language],
            );

            FestivalLoginToken::query()
                ->whereBelongsTo($account)
                ->where('email_normalized', $normalized)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            FestivalLoginToken::query()->create([
                'account_id' => $account->id,
                'festival_portal_user_id' => $portalUser->id,
                'email_normalized' => $normalized,
                'token_hash' => hash('sha256', $rawToken),
                'request_ip_hash' => hash('sha256', $ip),
                'expires_at' => now()->addMinutes(15),
            ]);

            return $portalUser;
        });

        $url = URL::temporarySignedRoute('festival.login.consume', now()->addMinutes(15), [
            'accountSlug' => $account->slug,
            'token' => $rawToken,
        ]);

        Mail::to($portalUser->email)->queue(new FestivalPortalMail(
            subjectLine: __('app.festival_magic_link_subject', locale: $portalUser->locale),
            greeting: __('app.festival_magic_link_greeting', locale: $portalUser->locale),
            lines: [__('app.festival_magic_link_copy', locale: $portalUser->locale)],
            actionLabel: __('app.festival_magic_link_action', locale: $portalUser->locale),
            actionUrl: $url,
            messageLocale: $portalUser->locale,
        ));
    }

    public function consume(Account $account, string $rawToken): FestivalPortalUser
    {
        return DB::transaction(function () use ($account, $rawToken): FestivalPortalUser {
            $token = FestivalLoginToken::query()
                ->whereBelongsTo($account)
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if (! $token || $token->consumed_at || $token->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => __('app.festival_magic_link_invalid')]);
            }

            $portalUser = FestivalPortalUser::query()
                ->whereBelongsTo($account)
                ->whereKey($token->festival_portal_user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $token->forceFill(['consumed_at' => now()])->save();
            $portalUser->forceFill([
                'email_verified_at' => $portalUser->email_verified_at ?? now(),
                'last_login_at' => now(),
            ])->save();

            return $portalUser;
        }, 3);
    }
}
