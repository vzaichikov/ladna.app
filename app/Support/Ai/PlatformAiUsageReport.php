<?php

namespace App\Support\Ai;

use App\Enums\AiConversationMessageRole;
use App\Models\AiConversationMessage;
use App\Models\AiProviderRequest;
use App\Models\AiUsageRestriction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PlatformAiUsageReport
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        [$start, $end] = $this->dateRange($filters);
        $providerRequests = $this->providerRequestQuery($filters, $start, $end);
        $messages = $this->messageQuery($filters, $start, $end);
        $turns = (clone $messages)
            ->where('role', AiConversationMessageRole::User->value)
            ->count();
        $outOfScope = (clone $messages)
            ->where('role', AiConversationMessageRole::RejectedIntent->value)
            ->count();
        $rateLimitedAttempts = (clone $messages)
            ->whereIn('metadata->fallback_reason', [
                'ai_rate_limited',
                'ai_provider_rate_limited',
                'ai_cooldown',
            ])
            ->count();
        $totals = (clone $providerRequests)
            ->toBase()
            ->selectRaw('COUNT(*) AS provider_requests')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) AS input_tokens')
            ->selectRaw('COALESCE(SUM(cached_input_tokens), 0) AS cached_input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) AS output_tokens')
            ->selectRaw('COALESCE(SUM(reasoning_tokens), 0) AS reasoning_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) AS total_tokens')
            ->first();
        $activeRestrictions = $this->activeRestrictionQuery($filters);

        return [
            'period' => [
                'key' => $filters['period'] ?? '30',
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'summary' => [
                'turns' => $turns,
                'provider_requests' => (int) ($totals?->provider_requests ?? 0),
                'input_tokens' => (int) ($totals?->input_tokens ?? 0),
                'cached_input_tokens' => (int) ($totals?->cached_input_tokens ?? 0),
                'output_tokens' => (int) ($totals?->output_tokens ?? 0),
                'reasoning_tokens' => (int) ($totals?->reasoning_tokens ?? 0),
                'total_tokens' => (int) ($totals?->total_tokens ?? 0),
                'out_of_scope' => $outOfScope,
                'out_of_scope_percentage' => $turns > 0
                    ? round(($outOfScope / $turns) * 100, 1)
                    : 0.0,
                'rate_limited_attempts' => $rateLimitedAttempts,
                'active_cooldowns' => (clone $activeRestrictions)->count(),
            ],
            'provider_breakdown' => $this->providerBreakdown(clone $providerRequests),
            'channel_breakdown' => $this->channelBreakdown(clone $providerRequests),
            'status_breakdown' => $this->statusBreakdown(clone $providerRequests),
            'top_accounts' => $this->topAccounts(clone $providerRequests),
            'top_users' => $this->topUsers(clone $providerRequests),
            'active_restrictions' => $activeRestrictions
                ->with(['user:id,name', 'lastAccount:id,name'])
                ->latest('blocked_until')
                ->limit(50)
                ->get(),
            'recent_requests' => $providerRequests
                ->with(['account:id,name', 'user:id,name'])
                ->select([
                    'id',
                    'account_id',
                    'user_id',
                    'channel',
                    'provider',
                    'model',
                    'request_type',
                    'provider_round',
                    'status',
                    'input_tokens',
                    'cached_input_tokens',
                    'output_tokens',
                    'reasoning_tokens',
                    'total_tokens',
                    'duration_ms',
                    'error_code',
                    'started_at',
                ])
                ->latest('started_at')
                ->limit(50)
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{Carbon, Carbon}
     */
    private function dateRange(array $filters): array
    {
        $period = (string) ($filters['period'] ?? '30');

        if ($period === 'custom' && filled($filters['from'] ?? null) && filled($filters['to'] ?? null)) {
            return [
                Carbon::createFromFormat('Y-m-d', (string) $filters['from'])->startOfDay(),
                Carbon::createFromFormat('Y-m-d', (string) $filters['to'])->endOfDay(),
            ];
        }

        $end = now()->endOfDay();
        $start = match ($period) {
            'today' => now()->startOfDay(),
            '7' => now()->subDays(6)->startOfDay(),
            default => now()->subDays(29)->startOfDay(),
        };

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AiProviderRequest>
     */
    private function providerRequestQuery(array $filters, Carbon $start, Carbon $end): Builder
    {
        return AiProviderRequest::query()
            ->whereBetween('started_at', [$start, $end])
            ->when($filters['account_id'] ?? null, fn (Builder $query, mixed $accountId): Builder => $query->where('account_id', $accountId))
            ->when($filters['user_id'] ?? null, fn (Builder $query, mixed $userId): Builder => $query->where('user_id', $userId))
            ->when($filters['channel'] ?? null, fn (Builder $query, mixed $channel): Builder => $query->where('channel', $channel))
            ->when($filters['provider'] ?? null, fn (Builder $query, mixed $provider): Builder => $query->where('provider', $provider))
            ->when($filters['model'] ?? null, fn (Builder $query, mixed $model): Builder => $query->where('model', $model))
            ->when($filters['status'] ?? null, fn (Builder $query, mixed $status): Builder => $query->where('status', $status));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AiConversationMessage>
     */
    private function messageQuery(array $filters, Carbon $start, Carbon $end): Builder
    {
        return AiConversationMessage::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->when($filters['account_id'] ?? null, fn (Builder $query, mixed $accountId): Builder => $query->where('account_id', $accountId))
            ->when($filters['user_id'] ?? null, function (Builder $query, mixed $userId): Builder {
                return $query->whereHas(
                    'conversation',
                    fn (Builder $query): Builder => $query->where('user_id', $userId),
                );
            })
            ->when($filters['channel'] ?? null, function (Builder $query, mixed $channel): Builder {
                return $query->whereHas(
                    'conversation',
                    fn (Builder $query): Builder => $query->where('channel', $channel),
                );
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AiUsageRestriction>
     */
    private function activeRestrictionQuery(array $filters): Builder
    {
        return AiUsageRestriction::query()
            ->where('blocked_until', '>', now())
            ->when($filters['account_id'] ?? null, fn (Builder $query, mixed $accountId): Builder => $query->where('last_account_id', $accountId))
            ->when($filters['user_id'] ?? null, fn (Builder $query, mixed $userId): Builder => $query->where('user_id', $userId))
            ->when($filters['channel'] ?? null, fn (Builder $query, mixed $channel): Builder => $query->where('last_channel', $channel));
    }

    /**
     * @param  Builder<AiProviderRequest>  $query
     * @return Collection<int, AiProviderRequest>
     */
    private function providerBreakdown(Builder $query): Collection
    {
        return $query
            ->select(['provider', 'model'])
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) AS tokens')
            ->groupBy(['provider', 'model'])
            ->orderByDesc('requests')
            ->limit(25)
            ->get();
    }

    /**
     * @param  Builder<AiProviderRequest>  $query
     */
    private function channelBreakdown(Builder $query): Collection
    {
        return $query
            ->select('channel')
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) AS tokens')
            ->groupBy('channel')
            ->orderByDesc('requests')
            ->get();
    }

    /**
     * @param  Builder<AiProviderRequest>  $query
     */
    private function statusBreakdown(Builder $query): Collection
    {
        return $query
            ->select('status')
            ->selectRaw('COUNT(*) AS requests')
            ->groupBy('status')
            ->orderByDesc('requests')
            ->get();
    }

    /**
     * @param  Builder<AiProviderRequest>  $query
     */
    private function topAccounts(Builder $query): Collection
    {
        return $query
            ->whereNotNull('account_id')
            ->with('account:id,name')
            ->select('account_id')
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) AS tokens')
            ->groupBy('account_id')
            ->orderByDesc('requests')
            ->limit(10)
            ->get();
    }

    /**
     * @param  Builder<AiProviderRequest>  $query
     */
    private function topUsers(Builder $query): Collection
    {
        return $query
            ->whereNotNull('user_id')
            ->with('user:id,name')
            ->select('user_id')
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) AS tokens')
            ->groupBy('user_id')
            ->orderByDesc('requests')
            ->limit(10)
            ->get();
    }
}
