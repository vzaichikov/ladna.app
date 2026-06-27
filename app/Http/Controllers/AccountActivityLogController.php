<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountActivityLogSettings;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

class AccountActivityLogController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('viewActivityLog', $account);

        $timezone = $this->accountTimezone($account);
        $action = trim((string) $request->query('action', ''));
        $actor = trim((string) $request->query('actor', ''));
        $dateFrom = $this->dateFilter($request->query('date_from'), startOfDay: true, timezone: $timezone);
        $dateTo = $this->dateFilter($request->query('date_to'), startOfDay: false, timezone: $timezone);

        $activityLogs = $account->activityLogs()
            ->when($action !== '', fn (Builder $query): Builder => $query->where('action', $action))
            ->when($actor !== '', function (Builder $query) use ($actor): void {
                $query->where(function (Builder $query) use ($actor): void {
                    $query->where('actor_name', 'like', "%{$actor}%")
                        ->orWhere('actor_email', 'like', "%{$actor}%");

                    if (ctype_digit($actor)) {
                        $query->orWhere('actor_user_id', (int) $actor);
                    }
                });
            })
            ->when($dateFrom, fn (Builder $query): Builder => $query->where('occurred_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query): Builder => $query->where('occurred_at', '<=', $dateTo))
            ->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString();

        return view('account-activity-logs.index', [
            'account' => $account,
            'activityLogs' => $activityLogs,
            'actions' => $account->activityLogs()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
            'action' => $action,
            'actor' => $actor,
            'dateFrom' => $dateFrom?->copy()->timezone($timezone)->toDateString() ?? '',
            'dateTo' => $dateTo?->copy()->timezone($timezone)->toDateString() ?? '',
            'retentionDays' => AccountActivityLogSettings::retentionDays(),
            'timezone' => $timezone,
        ]);
    }

    private function dateFilter(mixed $value, bool $startOfDay, string $timezone): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d H:i:s', "{$value} 00:00:00", $timezone);
        } catch (Throwable) {
            return null;
        }

        return ($startOfDay ? $date->startOfDay() : $date->endOfDay())
            ->timezone((string) config('app.timezone', 'UTC'));
    }

    private function accountTimezone(Account $account): string
    {
        $timezone = $account->timezone ?: (string) config('app.timezone', 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return (string) config('app.timezone', 'UTC');
        }

        return $timezone;
    }
}
