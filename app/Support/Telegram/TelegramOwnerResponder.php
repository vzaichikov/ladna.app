<?php

namespace App\Support\Telegram;

use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\TelegramChatAuthorization;
use App\Support\Ai\StudioAiInference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TelegramOwnerResponder
{
    public function __construct(private readonly StudioAiInference $studioAiInference) {}

    /**
     * @param  callable(string): mixed|null  $beforeProviderRequest
     */
    public function shouldStartBookingDialog(Account $account, string $text, ?TelegramChatAuthorization $authorization = null, ?callable $beforeProviderRequest = null): bool
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        if (! $this->containsBookingLanguage($normalized)) {
            return false;
        }

        return $this->studioAiInference->shouldStartBookingDialog($account, $text, $authorization, $beforeProviderRequest);
    }

    /**
     * @param  callable(string): mixed|null  $beforeProviderRequest
     * @return array{response: string, rejected: bool, used_ai: bool, follow_up_actions: array<int, string>, help_sources: array<int, mixed>, provider: string|null, model: string|null, fallback_reason: string|null}
     */
    public function respond(Account $account, string $text, ?TelegramChatAuthorization $authorization = null, ?callable $beforeProviderRequest = null): array
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        if ($normalized === '' || str_contains($normalized, 'help') || str_contains($normalized, 'допом') || str_contains($normalized, 'помощ')) {
            return [
                'response' => __('app.telegram_owner_help'),
                'rejected' => false,
                'used_ai' => false,
                'follow_up_actions' => [],
                'help_sources' => [],
                'provider' => null,
                'model' => null,
                'fallback_reason' => null,
            ];
        }

        $aiResult = $this->studioAiInference->respond($account, $text, $authorization, beforeProviderRequest: $beforeProviderRequest);

        if ($aiResult->rejected || $aiResult->usedAi) {
            return [
                'response' => $aiResult->text,
                'rejected' => $aiResult->rejected,
                'used_ai' => $aiResult->usedAi,
                'follow_up_actions' => $aiResult->followUpActions,
                'help_sources' => $aiResult->helpSources,
                'provider' => $aiResult->provider,
                'model' => $aiResult->model,
                'fallback_reason' => $aiResult->fallbackReason,
            ];
        }

        if ($this->asksStudioProfile($normalized)) {
            return [
                'response' => $this->studioProfileText($account),
                'rejected' => false,
                'used_ai' => false,
                'follow_up_actions' => [],
                'help_sources' => [],
                'provider' => null,
                'model' => null,
                'fallback_reason' => null,
            ];
        }

        if ($date = $this->requestedDate($account, $normalized)) {
            return [
                'response' => $this->classCountText($account, $date),
                'rejected' => false,
                'used_ai' => false,
                'follow_up_actions' => [],
                'help_sources' => [],
                'provider' => null,
                'model' => null,
                'fallback_reason' => null,
            ];
        }

        return [
            'response' => __('app.telegram_out_of_scope'),
            'rejected' => true,
            'used_ai' => false,
            'follow_up_actions' => [],
            'help_sources' => [],
            'provider' => null,
            'model' => null,
            'fallback_reason' => null,
        ];
    }

    private function containsBookingLanguage(string $text): bool
    {
        return str_contains($text, 'запис')
            || str_contains($text, 'запиш')
            || str_contains($text, 'брон')
            || str_contains($text, 'book')
            || str_contains($text, 'booking');
    }

    private function asksStudioProfile(string $text): bool
    {
        return str_contains($text, 'studio')
            || str_contains($text, 'студ')
            || str_contains($text, 'адрес')
            || str_contains($text, 'address')
            || str_contains($text, 'hours')
            || str_contains($text, 'граф');
    }

    private function requestedDate(Account $account, string $text): ?Carbon
    {
        $timezone = $account->timezone ?: config('app.timezone');

        if (str_contains($text, 'today') || str_contains($text, 'сьогодні') || str_contains($text, 'сегодня')) {
            return now($timezone)->startOfDay();
        }

        if (str_contains($text, 'tomorrow') || str_contains($text, 'завтра')) {
            return now($timezone)->addDay()->startOfDay();
        }

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $matches) === 1) {
            return Carbon::createFromFormat('Y-m-d', $matches[1], $timezone)->startOfDay();
        }

        if (str_contains($text, 'class') || str_contains($text, 'занят') || str_contains($text, 'трен')) {
            return now($timezone)->startOfDay();
        }

        return null;
    }

    private function studioProfileText(Account $account): string
    {
        $locations = $account->locations()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn ($location): string => trim($location->name.($location->address ? ': '.$location->address : '')))
            ->implode("\n");

        $hours = collect($account->openingHours())
            ->map(fn (array $day, int $weekday): string => $weekday.': '.($day['enabled'] ? $day['opens_at'].'-'.$day['closes_at'] : __('app.closed')))
            ->implode("\n");

        return trim($account->name."\n\n".__('app.locations').":\n".($locations ?: __('app.none'))."\n\n".__('app.opening_hours').":\n".$hours);
    }

    private function classCountText(Account $account, Carbon $day): string
    {
        $timezone = $account->timezone ?: config('app.timezone');
        $start = $day->copy()->timezone('UTC');
        $end = $day->copy()->endOfDay()->timezone('UTC');

        $count = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->whereBetween('starts_at', [$start, $end])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->count();

        return __('app.telegram_class_count_for_day', [
            'date' => $day->copy()->timezone($timezone)->toDateString(),
            'count' => $count,
        ]);
    }
}
