<?php

namespace App\Http\Controllers;

use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationRecipientKind;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountCustomerNotificationController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('viewActivityLog', $account);

        $search = trim((string) $request->query('search', ''));
        $status = $this->validStatus((string) $request->query('status', ''));
        $type = $this->validType((string) $request->query('type', ''));
        $channel = $this->validChannel((string) $request->query('channel', ''));

        $notifications = $account->customerNotifications()
            ->with([
                'account:id,name,slug,timezone',
                'customer' => function (BelongsTo $relation) use ($account): void {
                    $relation
                        ->select(['id', 'account_id', 'name', 'phone', 'email'])
                        ->whereBelongsTo($account);
                },
                'scheduledClass' => function (BelongsTo $relation) use ($account): void {
                    $relation
                        ->select(['id', 'account_id', 'location_id', 'room_id', 'class_type_id', 'trainer_id', 'title', 'starts_at', 'ends_at', 'status'])
                        ->whereBelongsTo($account);
                },
                'scheduledClass.location:id,account_id,name,timezone',
                'scheduledClass.room:id,account_id,name',
                'scheduledClass.classType:id,account_id,name,schedule_kind',
            ])
            ->where('recipient_kind', CustomerNotificationRecipientKind::Customer->value)
            ->where('channel', CustomerNotificationChannel::Sms->value)
            ->when($search !== '', fn (Builder $query): Builder => $this->applySearch($query, $account, $search))
            ->when($status !== '', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($type !== '', fn (Builder $query): Builder => $query->where('type', $type))
            ->when($channel !== '', fn (Builder $query): Builder => $query->where('channel', $channel))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25, ['*'], 'notifications_page')
            ->withQueryString();

        return view('platform.customer-notifications.index', [
            'account' => $account,
            'channels' => CustomerNotificationChannel::cases(),
            'channel' => $channel,
            'notifications' => $notifications,
            'search' => $search,
            'status' => $status,
            'statuses' => CustomerNotificationStatus::cases(),
            'type' => $type,
            'types' => CustomerNotificationType::cases(),
        ]);
    }

    private function applySearch(Builder $query, Account $account, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($account, $search): void {
            $query
                ->where('recipient_name', 'like', '%'.$search.'%')
                ->orWhere('recipient_phone', 'like', '%'.$search.'%')
                ->orWhere('text', 'like', '%'.$search.'%')
                ->orWhere('last_error', 'like', '%'.$search.'%')
                ->orWhere('payload', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn (Builder $query): Builder => $query
                    ->whereBelongsTo($account)
                    ->where(function (Builder $query) use ($search): void {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
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
        return in_array($status, array_column(CustomerNotificationStatus::cases(), 'value'), true) ? $status : '';
    }

    private function validType(string $type): string
    {
        return in_array($type, array_column(CustomerNotificationType::cases(), 'value'), true) ? $type : '';
    }

    private function validChannel(string $channel): string
    {
        return in_array($channel, array_column(CustomerNotificationChannel::cases(), 'value'), true) ? $channel : '';
    }
}
