<?php

namespace App\Http\Controllers;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountTelegramAlertController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('viewActivityLog', $account);

        $search = trim((string) $request->query('search', ''));
        $status = $this->validStatus((string) $request->query('status', ''));
        $type = $this->validType((string) $request->query('type', ''));
        $types = $this->trainerAlertTypes();

        $alerts = $account->telegramAlerts()
            ->with([
                'account:id,name,slug,timezone',
                'trainer' => function (BelongsTo $relation) use ($account): void {
                    $relation
                        ->select(['id', 'account_id', 'name', 'phone'])
                        ->whereBelongsTo($account);
                },
                'scheduledClass' => function (BelongsTo $relation) use ($account): void {
                    $relation
                        ->select(['id', 'account_id', 'location_id', 'room_id', 'class_type_id', 'trainer_id', 'title', 'starts_at', 'ends_at'])
                        ->whereBelongsTo($account);
                },
                'scheduledClass.location:id,account_id,name',
                'scheduledClass.room:id,account_id,name',
                'scheduledClass.classType:id,account_id,name,schedule_kind',
                'installation:id,scope_type,profile,bot_username',
            ])
            ->where('recipient_kind', TelegramAlertRecipientKind::Trainer->value)
            ->whereIn('type', array_column($types, 'value'))
            ->when($search !== '', fn (Builder $query): Builder => $this->applySearch($query, $account, $search))
            ->when($status !== '', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($type !== '', fn (Builder $query): Builder => $query->where('type', $type))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25, ['*'], 'alerts_page')
            ->withQueryString();

        return view('account-telegram-alerts.index', [
            'account' => $account,
            'alerts' => $alerts,
            'search' => $search,
            'status' => $status,
            'statuses' => TelegramAlertStatus::cases(),
            'type' => $type,
            'types' => $types,
        ]);
    }

    private function applySearch(Builder $query, Account $account, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($account, $search): void {
            $query
                ->where('type', 'like', '%'.$search.'%')
                ->orWhere('status', 'like', '%'.$search.'%')
                ->orWhere('telegram_chat_id', 'like', '%'.$search.'%')
                ->orWhere('text', 'like', '%'.$search.'%')
                ->orWhere('payload', 'like', '%'.$search.'%')
                ->orWhere('last_error', 'like', '%'.$search.'%')
                ->orWhereHas('trainer', fn (Builder $query): Builder => $query
                    ->whereBelongsTo($account)
                    ->where(function (Builder $query) use ($search): void {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%');
                    }))
                ->orWhereHas('scheduledClass', fn (Builder $query): Builder => $query
                    ->whereBelongsTo($account)
                    ->where(function (Builder $query) use ($account, $search): void {
                        $query->where('title', 'like', '%'.$search.'%')
                            ->orWhereHas('location', fn (Builder $query): Builder => $query
                                ->whereBelongsTo($account)
                                ->where('name', 'like', '%'.$search.'%'))
                            ->orWhereHas('room', fn (Builder $query): Builder => $query
                                ->whereBelongsTo($account)
                                ->where('name', 'like', '%'.$search.'%'));
                    }));
        });
    }

    private function validStatus(string $status): string
    {
        return in_array($status, array_column(TelegramAlertStatus::cases(), 'value'), true) ? $status : '';
    }

    private function validType(string $type): string
    {
        return in_array($type, array_column($this->trainerAlertTypes(), 'value'), true) ? $type : '';
    }

    /**
     * @return array<int, TelegramAlertType>
     */
    private function trainerAlertTypes(): array
    {
        return [
            TelegramAlertType::TrainerAssignment,
            TelegramAlertType::TrainerClassCancellation,
        ];
    }
}
