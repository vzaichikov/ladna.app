<?php

namespace App\Support\Telegram\Announcements;

use App\Enums\AccountMode;
use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\AccountMembership;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\User;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StudioOwnerAnnouncementAudienceResolver
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly StudioOwnerAnnouncementExecutionGuard $executionGuard,
    ) {}

    public function resolve(TelegramBotInstallation $installation): StudioOwnerAnnouncementAudience
    {
        $this->executionGuard->authorize();

        $owners = AccountMembership::query()
            ->where('role', AccountRole::Owner->value)
            ->whereHas('account', fn (Builder $query): Builder => $query
                ->active()
                ->operational())
            ->whereHas('user')
            ->with(['account', 'user'])
            ->orderBy('account_id')
            ->orderBy('user_id')
            ->get();

        $authorizations = TelegramChatAuthorization::query()
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->whereIn('account_id', $owners->pluck('account_id')->unique())
            ->with(['account', 'user'])
            ->orderBy('id')
            ->get()
            ->groupBy('account_id');

        $recipients = collect();
        $integrityErrors = collect();
        $excluded = [
            'owners_without_authorized_chat' => 0,
            'owners_without_phone' => 0,
            'owners_with_alerts_disabled' => 0,
            'ignored_non_owner_authorizations' => 0,
        ];

        foreach ($owners as $membership) {
            $owner = $membership->user;
            $account = $membership->account;

            if (! $owner || ! $account) {
                continue;
            }

            if (! $account->telegramAlertsEnabled()) {
                $excluded['owners_with_alerts_disabled']++;

                continue;
            }

            /** @var Collection<int, TelegramChatAuthorization> $accountAuthorizations */
            $accountAuthorizations = $authorizations->get($membership->account_id, collect());
            $ownerPhone = $this->phoneNumberNormalizer->normalize($owner->phone, $account->country_code);
            $exactAuthorizations = $accountAuthorizations
                ->filter(fn (TelegramChatAuthorization $authorization): bool => $authorization->user_id === $owner->id);
            $phoneAuthorizations = collect();

            if ($ownerPhone) {
                $conflictingAuthorizations = $accountAuthorizations->filter(
                    fn (TelegramChatAuthorization $authorization): bool => $authorization->user_id !== null
                        && $authorization->user_id !== $owner->id
                        && $this->authorizationPhone($authorization) === $ownerPhone,
                );

                $conflictingAuthorizations->each(function (TelegramChatAuthorization $authorization) use ($integrityErrors, $owner): void {
                    $integrityErrors->push(
                        "Authorization #{$authorization->id} phone matches owner #{$owner->id} but links user #{$authorization->user_id}.",
                    );
                });

                $phoneAuthorizations = $accountAuthorizations->filter(
                    fn (TelegramChatAuthorization $authorization): bool => $authorization->user_id === null
                        && $this->authorizationPhone($authorization) === $ownerPhone,
                );
            }

            $ownerAuthorizations = $exactAuthorizations
                ->merge($phoneAuthorizations)
                ->unique('id')
                ->values();

            if ($ownerAuthorizations->isEmpty()) {
                $excluded[$ownerPhone ? 'owners_without_authorized_chat' : 'owners_without_phone']++;

                continue;
            }

            $ownerAuthorizations->each(function (TelegramChatAuthorization $authorization) use ($recipients, $owner, $account): void {
                $recipients->push([
                    'authorization' => $authorization,
                    'owner' => $owner,
                    'account' => $account,
                    'locale' => $account->default_language === 'en' ? 'en' : 'uk',
                    'resolution' => $authorization->user_id === null ? 'phone' : 'user_id',
                ]);
            });
        }

        $ambiguousAuthorizations = $recipients
            ->groupBy(fn (array $recipient): int => $recipient['authorization']->id)
            ->filter(fn (Collection $matches): bool => $matches->pluck('owner.id')->unique()->count() > 1);

        $ambiguousAuthorizations->each(function (Collection $matches, int $authorizationId) use ($integrityErrors): void {
            $ownerIds = $matches->pluck('owner.id')->unique()->sort()->implode(', ');
            $integrityErrors->push("Authorization #{$authorizationId} matches multiple studio owners: {$ownerIds}.");
        });

        $recipients = $recipients
            ->unique(fn (array $recipient): string => (string) $recipient['authorization']->telegram_chat_id)
            ->values();

        $selectedAuthorizationIds = $recipients->pluck('authorization.id')->all();
        $excluded['ignored_non_owner_authorizations'] = $authorizations
            ->flatten(1)
            ->reject(fn (TelegramChatAuthorization $authorization): bool => in_array($authorization->id, $selectedAuthorizationIds, true))
            ->count();

        return new StudioOwnerAnnouncementAudience(
            recipients: $recipients,
            excluded: $excluded,
            integrityErrors: $integrityErrors->unique()->values()->all(),
        );
    }

    public function authorizationMatchesCurrentOwner(TelegramChatAuthorization $authorization, int $ownerUserId): bool
    {
        $authorization->loadMissing('account');
        $account = $authorization->account;

        if (
            ! $account
            || $account->status !== AccountStatus::Active
            || $account->mode !== AccountMode::Live
            || ! $account->telegramAlertsEnabled()
            || $authorization->profile !== TelegramBotProfile::Owner
            || $authorization->status !== TelegramChatAuthorizationStatus::Authorized
        ) {
            return false;
        }

        $membership = AccountMembership::query()
            ->where('account_id', $authorization->account_id)
            ->where('user_id', $ownerUserId)
            ->where('role', AccountRole::Owner->value)
            ->with('user')
            ->first();

        if (! $membership?->user) {
            return false;
        }

        if ($authorization->user_id !== null) {
            return $authorization->user_id === $ownerUserId;
        }

        return $this->phonesMatch($authorization, $membership->user);
    }

    private function phonesMatch(TelegramChatAuthorization $authorization, User $owner): bool
    {
        $ownerPhone = $this->phoneNumberNormalizer->normalize(
            $owner->phone,
            $authorization->account?->country_code ?? 'UA',
        );

        return $ownerPhone !== null && $ownerPhone === $this->authorizationPhone($authorization);
    }

    private function authorizationPhone(TelegramChatAuthorization $authorization): ?string
    {
        return $this->phoneNumberNormalizer->normalize(
            $authorization->phone,
            $authorization->account?->country_code ?? 'UA',
        );
    }
}
