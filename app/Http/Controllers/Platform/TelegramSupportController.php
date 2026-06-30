<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramUpdateStatus;
use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Support\Telegram\TelegramConversationResetter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TelegramSupportController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $authorizations = TelegramChatAuthorization::query()
            ->with(['account:id,name,slug,timezone', 'user:id,name,email,phone', 'trainer:id,account_id,name,phone', 'installation:id,scope_type,profile,bot_username'])
            ->withCount([
                'conversations as active_conversations_count' => fn (Builder $query): Builder => $query
                    ->where('channel', 'telegram_owner')
                    ->where('status', AiConversation::StatusActive),
            ])
            ->where('profile', TelegramBotProfile::Owner->value)
            ->when($search !== '', fn (Builder $query): Builder => $this->applyAuthorizationSearch($query, $search))
            ->latest('updated_at')
            ->paginate(15, ['*'], 'authorizations_page')
            ->withQueryString();

        $messages = TelegramMessage::query()
            ->with(['account:id,name,slug', 'authorization:id,user_id,trainer_id,status', 'authorization.user:id,name,email,phone', 'authorization.trainer:id,name,phone'])
            ->where('profile', TelegramBotProfile::Owner->value)
            ->when($search !== '', fn (Builder $query): Builder => $this->applyMessageSearch($query, $search))
            ->latest('sent_at')
            ->latest('id')
            ->paginate(25, ['*'], 'messages_page')
            ->withQueryString();

        $updates = TelegramUpdate::query()
            ->with(['account:id,name,slug', 'installation:id,scope_type,profile,bot_username'])
            ->where('profile', TelegramBotProfile::Owner->value)
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                    $query->where('update_id', 'like', '%'.$search.'%')
                        ->orWhere('error_message', 'like', '%'.$search.'%')
                        ->orWhere('payload', 'like', '%'.$search.'%')
                        ->orWhereHas('account', fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('slug', 'like', '%'.$search.'%'));
                })
            )
            ->whereIn('status', [
                TelegramUpdateStatus::Failed->value,
                TelegramUpdateStatus::Pending->value,
                TelegramUpdateStatus::Processing->value,
            ])
            ->latest('received_at')
            ->latest('id')
            ->paginate(15, ['*'], 'updates_page')
            ->withQueryString();

        return view('platform.telegram-support.index', [
            'authorizations' => $authorizations,
            'messages' => $messages,
            'updates' => $updates,
            'search' => $search,
        ]);
    }

    public function reset(Request $request, TelegramChatAuthorization $telegramAuthorization, TelegramConversationResetter $resetter): RedirectResponse
    {
        $this->ensureOwnerAuthorization($telegramAuthorization);

        $resetter->reset($telegramAuthorization);

        return back()->with('status', __('app.telegram_support_conversation_reset'));
    }

    public function revoke(Request $request, TelegramChatAuthorization $telegramAuthorization, TelegramConversationResetter $resetter): RedirectResponse
    {
        $this->ensureOwnerAuthorization($telegramAuthorization);

        $resetter->revoke($telegramAuthorization);

        return back()->with('status', __('app.telegram_support_authorization_revoked'));
    }

    private function applyAuthorizationSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('telegram_chat_id', 'like', '%'.$search.'%')
                ->orWhere('telegram_user_id', 'like', '%'.$search.'%')
                ->orWhere('telegram_username', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')
                ->orWhereHas('account', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%'))
                ->orWhereHas('user', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%'))
                ->orWhereHas('trainer', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%'));
        });
    }

    private function applyMessageSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('telegram_chat_id', 'like', '%'.$search.'%')
                ->orWhere('telegram_user_id', 'like', '%'.$search.'%')
                ->orWhere('text', 'like', '%'.$search.'%')
                ->orWhere('payload', 'like', '%'.$search.'%')
                ->orWhereHas('account', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%'))
                ->orWhereHas('authorization.user', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%'))
                ->orWhereHas('authorization.trainer', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%'));
        });
    }

    private function ensureOwnerAuthorization(TelegramChatAuthorization $authorization): void
    {
        abort_unless($authorization->profile === TelegramBotProfile::Owner, 404);
    }
}
