<?php

namespace App\Support\Ai;

use App\Enums\StudioAiDisposition;
use App\Models\Account;
use App\Models\AiUsageRestriction;
use App\Models\PlatformAiSetting;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class StudioAiUsageFirewall
{
    private const MinuteSeconds = 60;

    private const HourSeconds = 3600;

    private const DaySeconds = 86400;

    private const InferenceLockSeconds = 900;

    public function acquireInferenceLock(User $user): ?Lock
    {
        $lock = Cache::lock($this->inferenceLockKey($user), self::InferenceLockSeconds);

        return $lock->get() ? $lock : null;
    }

    public function busyDecision(): StudioAiFirewallDecision
    {
        return StudioAiFirewallDecision::deny('busy', 'user', 15);
    }

    public function admitTurn(
        Account $account,
        User $user,
        string $channel,
        PlatformAiSetting $setting,
    ): StudioAiFirewallDecision {
        if (! $setting->firewall_enabled) {
            return StudioAiFirewallDecision::allow();
        }

        $cooldown = $this->cooldownDecision($account, $user, $channel, $setting);

        if (! $cooldown->allowed) {
            return $cooldown;
        }

        return $this->withAccountCounterLock(
            $account,
            function () use ($account, $user, $setting): StudioAiFirewallDecision {
                $limits = $user->isPlatformAdmin()
                    ? [
                        ['scope' => 'user_minute', 'maximum' => $setting->firewall_admin_turns_per_minute, 'decay' => self::MinuteSeconds],
                        ['scope' => 'user_hour', 'maximum' => $setting->firewall_admin_turns_per_hour, 'decay' => self::HourSeconds],
                        ['scope' => 'user_day', 'maximum' => $setting->firewall_admin_turns_per_day, 'decay' => self::DaySeconds],
                    ]
                    : [
                        ['scope' => 'user_minute', 'maximum' => $setting->firewall_user_turns_per_minute, 'decay' => self::MinuteSeconds],
                        ['scope' => 'user_hour', 'maximum' => $setting->firewall_user_turns_per_hour, 'decay' => self::HourSeconds],
                        ['scope' => 'user_day', 'maximum' => $setting->firewall_user_turns_per_day, 'decay' => self::DaySeconds],
                    ];
                $limits[] = [
                    'scope' => 'account_day',
                    'maximum' => $setting->firewall_account_turns_per_day,
                    'decay' => self::DaySeconds,
                ];

                return $this->reserveLimits($account, $user, 'turn', $limits);
            },
        );
    }

    public function reserveProviderCall(
        Account $account,
        User $user,
        PlatformAiSetting $setting,
    ): StudioAiFirewallDecision {
        if (! $setting->firewall_enabled) {
            return StudioAiFirewallDecision::allow();
        }

        return $this->withAccountCounterLock(
            $account,
            function () use ($account, $user, $setting): StudioAiFirewallDecision {
                $limits = $user->isPlatformAdmin()
                    ? [
                        ['scope' => 'user_hour', 'maximum' => $setting->firewall_admin_provider_calls_per_hour, 'decay' => self::HourSeconds],
                        ['scope' => 'user_day', 'maximum' => $setting->firewall_admin_provider_calls_per_day, 'decay' => self::DaySeconds],
                    ]
                    : [
                        ['scope' => 'user_hour', 'maximum' => $setting->firewall_user_provider_calls_per_hour, 'decay' => self::HourSeconds],
                        ['scope' => 'user_day', 'maximum' => $setting->firewall_user_provider_calls_per_day, 'decay' => self::DaySeconds],
                    ];
                $limits[] = [
                    'scope' => 'account_day',
                    'maximum' => $setting->firewall_account_provider_calls_per_day,
                    'decay' => self::DaySeconds,
                ];

                return $this->reserveLimits($account, $user, 'provider', $limits);
            },
        );
    }

    public function resultForDecision(StudioAiFirewallDecision $decision, Account $account): StudioAiResult
    {
        $blockedUntil = $decision->blockedUntil
            ?? now()->addSeconds($decision->retryAfterSeconds ?? 1);
        $displayTime = $blockedUntil
            ->copy()
            ->timezone($account->timezone ?: config('app.timezone'))
            ->format('Y-m-d H:i');
        $text = match ($decision->reason) {
            'cooldown' => __('app.ai_firewall_cooldown', ['time' => $displayTime]),
            'provider_limit' => __('app.ai_firewall_provider_limit', ['time' => $displayTime]),
            'busy' => __('app.ai_firewall_busy', [
                'seconds' => $decision->retryAfterSeconds ?? 1,
            ]),
            default => __('app.ai_firewall_turn_limit', ['time' => $displayTime]),
        };

        return StudioAiResult::restriction(
            $text,
            match ($decision->reason) {
                'cooldown' => 'ai_cooldown',
                'provider_limit' => 'ai_provider_rate_limited',
                'busy' => 'ai_busy',
                default => 'ai_rate_limited',
            },
            $decision->scope,
            $decision->retryAfterSeconds,
            $blockedUntil,
        );
    }

    public function recordOutcome(
        Account $account,
        User $user,
        string $channel,
        StudioAiResult $result,
        PlatformAiSetting $setting,
    ): StudioAiResult {
        if (! $setting->firewall_enabled) {
            return $result;
        }

        if ($result->rejected && $result->disposition === StudioAiDisposition::OutOfScope) {
            return $this->recordOutOfScope($account, $user, $channel, $result, $setting);
        }

        if ($result->usedAi) {
            AiUsageRestriction::query()
                ->whereBelongsTo($user)
                ->where('consecutive_out_of_scope_count', '>', 0)
                ->update([
                    'consecutive_out_of_scope_count' => 0,
                    'last_account_id' => $account->id,
                    'last_channel' => $channel,
                    'updated_at' => now(),
                ]);
        }

        return $result;
    }

    public function resetUser(User $user, User $administrator): void
    {
        foreach (['turn', 'provider'] as $kind) {
            foreach (['minute', 'hour', 'day'] as $window) {
                RateLimiter::clear($this->userCounterKey($user, $kind, $window));
            }
        }

        AiUsageRestriction::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'consecutive_out_of_scope_count' => 0,
                'cooldown_level' => 0,
                'blocked_reason' => null,
                'blocked_until' => null,
                'last_out_of_scope_at' => null,
                'last_blocked_at' => null,
                'manually_unblocked_at' => now(),
                'manually_unblocked_by_user_id' => $administrator->id,
            ],
        );
    }

    public function resetAccount(Account $account): void
    {
        RateLimiter::clear($this->accountCounterKey($account, 'turn', 'day'));
        RateLimiter::clear($this->accountCounterKey($account, 'provider', 'day'));
    }

    public function safetyIdentifier(User $user): string
    {
        return hash_hmac('sha256', 'ladna-ai-user:'.$user->id, (string) config('app.key'));
    }

    /**
     * @return Builder<AiUsageRestriction>
     */
    public function activeRestrictions(): Builder
    {
        return AiUsageRestriction::query()->where('blocked_until', '>', now());
    }

    /**
     * @param  array<int, array{scope: string, maximum: int, decay: int}>  $limits
     */
    private function reserveLimits(
        Account $account,
        User $user,
        string $kind,
        array $limits,
    ): StudioAiFirewallDecision {
        foreach ($limits as $limit) {
            $key = $limit['scope'] === 'account_day'
                ? $this->accountCounterKey($account, $kind, 'day')
                : $this->userCounterKey($user, $kind, str($limit['scope'])->afterLast('_')->toString());

            if (RateLimiter::tooManyAttempts($key, $limit['maximum'])) {
                $retryAfter = max(1, RateLimiter::availableIn($key));

                return StudioAiFirewallDecision::deny(
                    $kind === 'provider' ? 'provider_limit' : 'turn_limit',
                    $limit['scope'],
                    $retryAfter,
                    now()->addSeconds($retryAfter),
                );
            }
        }

        foreach ($limits as $limit) {
            $key = $limit['scope'] === 'account_day'
                ? $this->accountCounterKey($account, $kind, 'day')
                : $this->userCounterKey($user, $kind, str($limit['scope'])->afterLast('_')->toString());
            RateLimiter::hit($key, $limit['decay']);
        }

        return StudioAiFirewallDecision::allow();
    }

    private function cooldownDecision(
        Account $account,
        User $user,
        string $channel,
        PlatformAiSetting $setting,
    ): StudioAiFirewallDecision {
        $restriction = AiUsageRestriction::query()->whereBelongsTo($user)->first();

        if (! $restriction) {
            return StudioAiFirewallDecision::allow();
        }

        if ($restriction->blocked_until?->isFuture()) {
            return StudioAiFirewallDecision::deny(
                'cooldown',
                'user',
                max(1, (int) now()->diffInSeconds($restriction->blocked_until)),
                $restriction->blocked_until,
            );
        }

        $updates = [];

        if ($restriction->blocked_until !== null) {
            $updates = [
                'blocked_reason' => null,
                'blocked_until' => null,
                'consecutive_out_of_scope_count' => 0,
                'last_account_id' => $account->id,
                'last_channel' => $channel,
            ];
        }

        if ($restriction->last_blocked_at?->lte(now()->subDays($setting->firewall_escalation_reset_days))) {
            $updates['cooldown_level'] = 0;
        }

        if ($updates !== []) {
            $restriction->forceFill($updates)->save();
        }

        return StudioAiFirewallDecision::allow();
    }

    private function recordOutOfScope(
        Account $account,
        User $user,
        string $channel,
        StudioAiResult $result,
        PlatformAiSetting $setting,
    ): StudioAiResult {
        return DB::transaction(function () use ($account, $user, $channel, $result, $setting): StudioAiResult {
            $restriction = AiUsageRestriction::query()
                ->whereBelongsTo($user)
                ->lockForUpdate()
                ->first() ?? new AiUsageRestriction(['user_id' => $user->id]);

            if ($restriction->last_blocked_at?->lte(now()->subDays($setting->firewall_escalation_reset_days))) {
                $restriction->cooldown_level = 0;
            }

            $restriction->fill([
                'last_account_id' => $account->id,
                'last_channel' => $channel,
                'last_out_of_scope_at' => now(),
                'consecutive_out_of_scope_count' => $restriction->consecutive_out_of_scope_count + 1,
            ]);

            $threshold = $user->isPlatformAdmin()
                ? $setting->firewall_admin_out_of_scope_streak
                : $setting->firewall_user_out_of_scope_streak;

            if ($restriction->consecutive_out_of_scope_count < $threshold) {
                $restriction->save();

                return $result;
            }

            $level = min(3, max(1, $restriction->cooldown_level + 1));
            $cooldownMinutes = match ($level) {
                1 => $setting->firewall_cooldown_first_minutes,
                2 => $setting->firewall_cooldown_second_minutes,
                default => $setting->firewall_cooldown_third_minutes,
            };
            $blockedUntil = now()->addMinutes($cooldownMinutes);
            $restriction->fill([
                'cooldown_level' => $level,
                'blocked_reason' => 'consecutive_out_of_scope',
                'blocked_until' => $blockedUntil,
                'last_blocked_at' => now(),
                'manually_unblocked_at' => null,
                'manually_unblocked_by_user_id' => null,
            ])->save();

            $displayTime = $blockedUntil
                ->copy()
                ->timezone($account->timezone ?: config('app.timezone'))
                ->format('Y-m-d H:i');

            return $result->withRestriction(
                $result->text."\n\n".__('app.ai_firewall_out_of_scope_blocked', ['time' => $displayTime]),
                'ai_cooldown',
                'user',
                max(1, (int) now()->diffInSeconds($blockedUntil)),
                $blockedUntil,
            );
        });
    }

    private function inferenceLockKey(User $user): string
    {
        return 'ai-firewall:inference:user:'.$user->id;
    }

    private function accountCounterLockKey(Account $account): string
    {
        return 'ai-firewall:counters:account:'.$account->id;
    }

    /**
     * @param  callable(): StudioAiFirewallDecision  $callback
     */
    private function withAccountCounterLock(
        Account $account,
        callable $callback,
    ): StudioAiFirewallDecision {
        $lock = Cache::lock($this->accountCounterLockKey($account), 5);

        if (! $lock->get()) {
            return StudioAiFirewallDecision::deny('busy', 'account', 2);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function userCounterKey(User $user, string $kind, string $window): string
    {
        return "ai-firewall:user:{$user->id}:{$kind}:{$window}";
    }

    private function accountCounterKey(Account $account, string $kind, string $window): string
    {
        return "ai-firewall:account:{$account->id}:{$kind}:{$window}";
    }
}
