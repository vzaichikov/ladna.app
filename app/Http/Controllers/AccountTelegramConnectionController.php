<?php

namespace App\Http\Controllers;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\CustomerNotification;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Support\Telegram\CustomerTelegramConnectionManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountTelegramConnectionController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('manageStudioSettings', $account);

        $search = trim((string) $request->query('search', ''));
        $activeTab = $this->activeTab((string) $request->query('tab', 'connections'));
        $installation = $account->telegramBotInstallations()
            ->where('scope_type', 'account')
            ->where('scope_id', $account->id)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->first();
        $installationId = $installation?->id ?? 0;

        $authorizationQuery = TelegramChatAuthorization::query()
            ->whereBelongsTo($account)
            ->where('telegram_bot_installation_id', $installationId)
            ->where('profile', TelegramBotProfile::Customer->value);
        $messageQuery = TelegramMessage::query()
            ->whereBelongsTo($account)
            ->where('telegram_bot_installation_id', $installationId)
            ->where('profile', TelegramBotProfile::Customer->value);
        $updateQuery = TelegramUpdate::query()
            ->whereBelongsTo($account)
            ->where('telegram_bot_installation_id', $installationId)
            ->where('profile', TelegramBotProfile::Customer->value);
        $notificationQuery = CustomerNotification::query()
            ->whereBelongsTo($account)
            ->telegramInteraction();

        $authorizations = (clone $authorizationQuery)
            ->with(['customer:id,account_id,name,email,phone,default_language'])
            ->when($search !== '', fn (Builder $query): Builder => $this->applyAuthorizationSearch($query, $search))
            ->latest('updated_at')
            ->paginate(15, ['*'], 'connections_page')
            ->withQueryString();
        $messages = (clone $messageQuery)
            ->with(['authorization:id,customer_id,status', 'authorization.customer:id,account_id,name,email,phone'])
            ->when($search !== '', fn (Builder $query): Builder => $this->applyMessageSearch($query, $search))
            ->latest('sent_at')
            ->latest('id')
            ->paginate(25, ['*'], 'messages_page')
            ->withQueryString();
        $updates = (clone $updateQuery)
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                    $query->where('update_id', 'like', '%'.$search.'%')
                        ->orWhere('error_message', 'like', '%'.$search.'%')
                        ->orWhere('payload', 'like', '%'.$search.'%');
                }),
            )
            ->latest('received_at')
            ->latest('id')
            ->paginate(25, ['*'], 'updates_page')
            ->withQueryString();
        $notifications = (clone $notificationQuery)
            ->with(['customer:id,account_id,name,email,phone'])
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                    $query->where('recipient_name', 'like', '%'.$search.'%')
                        ->orWhere('recipient_phone', 'like', '%'.$search.'%')
                        ->orWhere('text', 'like', '%'.$search.'%')
                        ->orWhere('last_error', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%'));
                }),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate(25, ['*'], 'notifications_page')
            ->withQueryString();

        return view('accounts.telegram-connections.index', [
            'account' => $account,
            'activeConnectionsCount' => (clone $authorizationQuery)
                ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
                ->count(),
            'activeTab' => $activeTab,
            'authorizations' => $authorizations,
            'installation' => $installation,
            'latestMessage' => (clone $messageQuery)->latest('sent_at')->latest('id')->first(),
            'latestUpdate' => (clone $updateQuery)->latest('received_at')->latest('id')->first(),
            'messages' => $messages,
            'notifications' => $notifications,
            'search' => $search,
            'tabs' => $this->tabs(),
            'totalConnectionsCount' => (clone $authorizationQuery)->count(),
            'updates' => $updates,
        ]);
    }

    public function reset(
        Request $request,
        Account $account,
        TelegramChatAuthorization $telegramAuthorization,
        CustomerTelegramConnectionManager $connections,
    ): RedirectResponse {
        $this->authorize('manageStudioSettings', $account);
        $this->ensureCustomerAuthorization($account, $telegramAuthorization);
        abort_unless($telegramAuthorization->status === TelegramChatAuthorizationStatus::Authorized, 422);

        $connections->reset($telegramAuthorization);

        return back()->with('status', __('app.telegram_customer_session_reset'));
    }

    public function revoke(
        Request $request,
        Account $account,
        TelegramChatAuthorization $telegramAuthorization,
        CustomerTelegramConnectionManager $connections,
    ): RedirectResponse {
        $this->authorize('manageStudioSettings', $account);
        $this->ensureCustomerAuthorization($account, $telegramAuthorization);

        $connections->revoke($telegramAuthorization);

        return back()->with('status', __('app.telegram_support_authorization_revoked'));
    }

    private function applyAuthorizationSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query->where('telegram_chat_id', 'like', '%'.$search.'%')
                ->orWhere('telegram_user_id', 'like', '%'.$search.'%')
                ->orWhere('telegram_username', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%'));
        });
    }

    private function applyMessageSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query->where('telegram_chat_id', 'like', '%'.$search.'%')
                ->orWhere('telegram_user_id', 'like', '%'.$search.'%')
                ->orWhere('text', 'like', '%'.$search.'%')
                ->orWhere('payload', 'like', '%'.$search.'%')
                ->orWhereHas('authorization.customer', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%'));
        });
    }

    private function ensureCustomerAuthorization(Account $account, TelegramChatAuthorization $authorization): void
    {
        $belongsToCurrentCustomerBot = $account->telegramBotInstallations()
            ->whereKey($authorization->telegram_bot_installation_id)
            ->where('scope_type', 'account')
            ->where('scope_id', $account->id)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->exists();

        abort_unless(
            $authorization->account_id === $account->id
            && $authorization->profile === TelegramBotProfile::Customer
            && $belongsToCurrentCustomerBot,
            404,
        );
    }

    private function activeTab(string $tab): string
    {
        return array_key_exists($tab, $this->tabs()) ? $tab : 'connections';
    }

    /**
     * @return array<string, array{label_key: string, panel_id: string}>
     */
    private function tabs(): array
    {
        return [
            'connections' => [
                'label_key' => 'app.telegram_customer_connections',
                'panel_id' => 'telegram-customer-connections',
            ],
            'messages' => [
                'label_key' => 'app.telegram_message_logs',
                'panel_id' => 'telegram-customer-messages',
            ],
            'notifications' => [
                'label_key' => 'app.telegram_customer_notification_logs',
                'panel_id' => 'telegram-customer-notifications',
            ],
            'updates' => [
                'label_key' => 'app.telegram_update_logs',
                'panel_id' => 'telegram-customer-updates',
            ],
        ];
    }
}
