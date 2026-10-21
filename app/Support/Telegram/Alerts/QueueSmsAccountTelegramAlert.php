<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\AccountMode;
use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Support\Sms\SmsAccountStatus;

class QueueSmsAccountTelegramAlert
{
    public function __construct(
        private readonly TelegramAlertProducer $alerts,
        private readonly SmsAccountStatus $status,
    ) {}

    public function execute(Account $account): int
    {
        $account->load('smsWallet');
        if ($account->mode !== AccountMode::Live || $account->status !== AccountStatus::Active || ! $account->smsWallet?->telegram_alerts_enabled) {
            return 0;
        }

        $summary = $this->status->forAccount($account);
        $issue = $summary['issue'];
        $episode = $issue ? $account->smsWallet->{$this->status->warningTimestampField($issue)}?->toISOString() : null;
        if (! $episode) {
            return 0;
        }

        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('is_enabled', true)->whereNotNull('encrypted_token')
            ->latest('updated_at')->latest('id')->first();
        if (! $installation || ! $installation->tokenValue()) {
            return 0;
        }

        $authorizations = TelegramChatAuthorization::query()
            ->where('account_id', $account->id)
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->whereIn('user_id', $account->memberships()->where('role', AccountRole::Owner->value)->select('user_id'))
            ->latest('authorized_at')->latest('id')->get()->unique('telegram_chat_id');

        foreach ($authorizations as $authorization) {
            $dedupeKey = 'sms-account:'.$account->id.':'.$issue.':'.$episode.':'.$authorization->id;
            $previous = TelegramAlert::query()
                ->where('account_id', $account->id)
                ->where('type', TelegramAlertType::SmsAccountIssue->value)
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->where('payload->issue', $issue)
                ->where('payload->episode', $episode)
                ->latest('id')->first();

            if ($previous) {
                if ($previous->status !== TelegramAlertStatus::Failed || ! in_array($previous->last_error, [
                    'sms_account_alerts_disabled',
                    'sms_account_issue_no_longer_relevant',
                    'studio_owner_telegram_authorization_missing',
                    'owner_bot_not_configured',
                    'owner_bot_token_missing',
                ], true)) {
                    continue;
                }

                $dedupeKey .= ':after:'.$previous->id;
            }

            $this->alerts->queue(
                TelegramAlertType::SmsAccountIssue,
                $account,
                TelegramAlertRecipientKind::StudioOwner,
                [
                    'issue' => $issue,
                    'episode' => $episode,
                    'warning_key' => $summary['warningKey'],
                    'warning_parameters' => $summary['warningParameters'],
                    'spendable_cents' => $summary['spendableCents'],
                    'action_url' => route('dashboard.accounts.sms-account.show', $account),
                ],
                [
                    'telegram_bot_installation_id' => $installation->id,
                    'telegram_chat_authorization_id' => $authorization->id,
                    'telegram_chat_id' => $authorization->telegram_chat_id,
                    'telegram_user_id' => $authorization->telegram_user_id,
                ],
                $dedupeKey,
            );
        }

        return $authorizations->count();
    }
}
