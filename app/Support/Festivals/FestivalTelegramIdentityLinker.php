<?php

namespace App\Support\Festivals;

use App\Enums\FestivalPortalRole;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramFestivalPortalLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FestivalTelegramIdentityLinker
{
    /**
     * @param  array{first_name?: string, last_name?: string, username?: string, language_code?: string}  $telegramUser
     * @return array{authorization: TelegramChatAuthorization, registrant: FestivalPortalUser}
     */
    public function authorizeRegistrant(
        FestivalSeries $series,
        TelegramBotInstallation $installation,
        string $chatId,
        string $telegramUserId,
        string $phone,
        array $telegramUser,
    ): array {
        $this->assertSeriesInstallation($series, $installation);

        return DB::transaction(function () use ($series, $installation, $chatId, $telegramUserId, $phone, $telegramUser): array {
            $authorization = TelegramChatAuthorization::query()
                ->where('telegram_bot_installation_id', $installation->id)
                ->where('telegram_chat_id', $chatId)
                ->lockForUpdate()
                ->first();
            $userAuthorization = TelegramChatAuthorization::query()
                ->where('telegram_bot_installation_id', $installation->id)
                ->where('telegram_user_id', $telegramUserId)
                ->where('telegram_chat_id', '!=', $chatId)
                ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
                ->lockForUpdate()
                ->first();

            if ($userAuthorization || ($authorization && filled($authorization->telegram_user_id) && $authorization->telegram_user_id !== $telegramUserId)) {
                throw ValidationException::withMessages(['contact' => __('app.festival_telegram_identity_conflict')]);
            }

            $phoneMatch = FestivalPortalUser::query()
                ->where('account_id', $series->account_id)
                ->where('role', FestivalPortalRole::Registrant->value)
                ->where('phone_normalized', $phone)
                ->lockForUpdate()
                ->first();
            $telegramMatch = FestivalPortalUser::query()
                ->where('account_id', $series->account_id)
                ->where('role', FestivalPortalRole::Registrant->value)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first();

            if (($phoneMatch && ! $phoneMatch->is_active) || ($telegramMatch && ! $telegramMatch->is_active)) {
                throw ValidationException::withMessages(['contact' => __('app.festival_profile_inactive')]);
            }

            if (($phoneMatch && $telegramMatch && ! $phoneMatch->is($telegramMatch))
                || ($phoneMatch && filled($phoneMatch->telegram_user_id) && $phoneMatch->telegram_user_id !== $telegramUserId)
                || ($telegramMatch && filled($telegramMatch->phone_normalized) && $telegramMatch->phone_normalized !== $phone)) {
                throw ValidationException::withMessages(['contact' => __('app.festival_telegram_identity_conflict')]);
            }

            $registrant = $phoneMatch ?? $telegramMatch;

            if (! $registrant) {
                $registrant = FestivalPortalUser::query()->create([
                    'account_id' => $series->account_id,
                    'role' => FestivalPortalRole::Registrant,
                    'is_active' => true,
                    'first_name' => trim((string) ($telegramUser['first_name'] ?? '')) ?: null,
                    'last_name' => trim((string) ($telegramUser['last_name'] ?? '')) ?: null,
                    'phone' => $phone,
                    'phone_normalized' => $phone,
                    'phone_verified_at' => now(),
                    'telegram_user_id' => $telegramUserId,
                    'telegram_contact' => filled($telegramUser['username'] ?? null) ? '@'.ltrim((string) $telegramUser['username'], '@') : null,
                    'locale' => $this->locale($series, (string) ($telegramUser['language_code'] ?? '')),
                ]);
            } else {
                $registrant->forceFill([
                    'phone' => $phone,
                    'phone_normalized' => $phone,
                    'phone_verified_at' => $registrant->phone_verified_at ?? now(),
                    'telegram_user_id' => $telegramUserId,
                    'telegram_contact' => filled($telegramUser['username'] ?? null)
                        ? '@'.ltrim((string) $telegramUser['username'], '@')
                        : $registrant->telegram_contact,
                ])->save();
            }

            $authorization ??= new TelegramChatAuthorization;
            $authorization->forceFill([
                'account_id' => $series->account_id,
                'telegram_bot_installation_id' => $installation->id,
                'user_id' => null,
                'trainer_id' => null,
                'customer_id' => null,
                'profile' => TelegramBotProfile::Festival->value,
                'telegram_chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => filled($telegramUser['username'] ?? null) ? ltrim((string) $telegramUser['username'], '@') : null,
                'phone' => $phone,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
                'authorized_at' => now(),
                'revoked_at' => null,
            ])->save();

            $this->linkPortalUser($authorization, $registrant);

            return ['authorization' => $authorization, 'registrant' => $registrant];
        }, 3);
    }

    public function linkPortalUser(TelegramChatAuthorization $authorization, FestivalPortalUser $portalUser): TelegramFestivalPortalLink
    {
        $authorization->loadMissing('installation');
        $installation = $authorization->installation;

        if ((int) $authorization->account_id !== (int) $portalUser->account_id
            || $authorization->status !== TelegramChatAuthorizationStatus::Authorized
            || ! $portalUser->is_active
            || ! $installation
            || $installation->profile !== TelegramBotProfile::Festival
            || $installation->scope_type !== 'festival_series'
            || ! $installation->is_enabled) {
            throw ValidationException::withMessages(['telegram' => __('app.festival_telegram_authorization_invalid')]);
        }

        return TelegramFestivalPortalLink::query()->firstOrCreate([
            'telegram_chat_authorization_id' => $authorization->id,
            'festival_portal_user_id' => $portalUser->id,
        ], [
            'account_id' => $portalUser->account_id,
        ]);
    }

    public function linkGuestOrder(TelegramChatAuthorization $authorization, FestivalTicketOrder $order): TelegramFestivalPortalLink
    {
        $order->loadMissing(['edition', 'portalUser']);
        $authorization->loadMissing('installation');
        $guest = $order->portalUser;

        if (! $guest
            || $guest->role !== FestivalPortalRole::Guest
            || (int) $order->account_id !== (int) $authorization->account_id
            || (int) $order->edition->festival_series_id !== (int) $authorization->installation?->scope_id) {
            throw ValidationException::withMessages(['telegram' => __('app.festival_telegram_authorization_invalid')]);
        }

        if (filled($guest->telegram_user_id) && $guest->telegram_user_id !== $authorization->telegram_user_id) {
            throw ValidationException::withMessages(['telegram' => __('app.festival_telegram_identity_conflict')]);
        }

        $telegramGuest = FestivalPortalUser::query()
            ->where('account_id', $guest->account_id)
            ->where('role', FestivalPortalRole::Guest->value)
            ->where('telegram_user_id', $authorization->telegram_user_id)
            ->whereKeyNot($guest->id)
            ->first();

        if ($telegramGuest) {
            throw ValidationException::withMessages(['telegram' => __('app.festival_telegram_identity_conflict')]);
        }

        $guest->forceFill(['telegram_user_id' => $authorization->telegram_user_id])->save();

        return $this->linkPortalUser($authorization, $guest);
    }

    private function assertSeriesInstallation(FestivalSeries $series, TelegramBotInstallation $installation): void
    {
        abort_unless(
            (int) $installation->account_id === (int) $series->account_id
            && $installation->scope_type === 'festival_series'
            && (int) $installation->scope_id === (int) $series->id
            && $installation->profile === TelegramBotProfile::Festival
            && $installation->is_enabled,
            404,
        );
    }

    private function locale(FestivalSeries $series, string $languageCode): string
    {
        $candidate = str($languageCode)->before('-')->lower()->toString();

        return array_key_exists($candidate, config('ladna.locales', []))
            ? $candidate
            : (string) $series->account()->value('default_language');
    }
}
