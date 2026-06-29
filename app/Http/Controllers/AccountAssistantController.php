<?php

namespace App\Http\Controllers;

use App\Enums\AiConversationMessageRole;
use App\Enums\StudioPermission;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\PlatformAiSetting;
use App\Models\Trainer;
use App\Support\Ai\StudioAiInference;
use App\Support\Ai\StudioAssistantActionExecutor;
use App\Support\Ai\StudioAssistantActionPlanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountAssistantController extends Controller
{
    public function show(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAssistant($request, $account);
        $conversation = $this->conversationFor($account, $request);

        return response()->json([
            'messages' => $this->messagePayload($conversation),
            'pending_actions' => $this->pendingActionPayload($conversation),
        ]);
    }

    public function store(Request $request, Account $account, StudioAiInference $inference, StudioAssistantActionPlanner $planner): JsonResponse
    {
        $this->authorizeAssistant($request, $account);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $conversation = $this->conversationFor($account, $request);
        $text = trim((string) $validated['message']);

        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => $text,
            'occurred_at' => now(),
        ]);

        $pendingAction = $planner->plan($account, $request->user(), $this->trainerFor($account, $request), $conversation, $text);

        if ($pendingAction) {
            $assistantText = __('app.assistant_pending_action_created');
            $conversation->messages()->create([
                'account_id' => $account->id,
                'role' => AiConversationMessageRole::Assistant->value,
                'content' => $assistantText,
                'metadata' => [
                    'pending_action_id' => $pendingAction->id,
                    'used_ai' => false,
                ],
                'occurred_at' => now(),
            ]);
        } else {
            $result = $inference->respond($account, $text, conversation: $conversation);
            $assistantText = $result->text !== '' ? $result->text : __('app.assistant_ai_unavailable');
            $conversation->messages()->create([
                'account_id' => $account->id,
                'role' => $result->rejected ? AiConversationMessageRole::RejectedIntent->value : AiConversationMessageRole::Assistant->value,
                'content' => $assistantText,
                'metadata' => [
                    'used_ai' => $result->usedAi,
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'fallback_reason' => $result->fallbackReason,
                    'follow_up_actions' => $result->followUpActions,
                ],
                'occurred_at' => now(),
            ]);
        }

        $conversation->update(['last_message_at' => now()]);

        return response()->json([
            'messages' => $this->messagePayload($conversation->refresh()),
            'pending_actions' => $this->pendingActionPayload($conversation),
        ]);
    }

    public function confirm(Request $request, Account $account, AiPendingAction $action, StudioAssistantActionExecutor $executor): JsonResponse
    {
        $this->authorizeAssistant($request, $account);
        $this->ensureActionBelongsToAccount($account, $action);

        try {
            $result = $executor->execute($action, $request->user());
        } catch (ValidationException $exception) {
            $action->update([
                'status' => AiPendingAction::StatusFailed,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $conversation = $action->conversation()->firstOrFail();
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::Tool->value,
            'content' => $result['message'] ?? __('app.assistant_action_executed'),
            'metadata' => [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'result' => $result,
            ],
            'occurred_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        return response()->json([
            'message' => $result['message'] ?? __('app.assistant_action_executed'),
            'messages' => $this->messagePayload($conversation->refresh()),
            'pending_actions' => $this->pendingActionPayload($conversation),
        ]);
    }

    public function cancel(Request $request, Account $account, AiPendingAction $action): JsonResponse
    {
        $this->authorizeAssistant($request, $account);
        $this->ensureActionBelongsToAccount($account, $action);

        if (! $action->isPending()) {
            throw ValidationException::withMessages([
                'action' => __('app.assistant_action_not_pending'),
            ]);
        }

        $action->update([
            'status' => AiPendingAction::StatusCancelled,
            'cancelled_at' => now(),
        ]);

        $conversation = $action->conversation()->firstOrFail();
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::Tool->value,
            'content' => __('app.assistant_action_cancelled'),
            'metadata' => [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
            ],
            'occurred_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        return response()->json([
            'message' => __('app.assistant_action_cancelled'),
            'messages' => $this->messagePayload($conversation->refresh()),
            'pending_actions' => $this->pendingActionPayload($conversation),
        ]);
    }

    private function authorizeAssistant(Request $request, Account $account): void
    {
        $this->authorize('view', $account);

        if (! PlatformAiSetting::ownerAssistantEnabled()) {
            abort(404);
        }

        if (! $account->userCan($request->user(), StudioPermission::InteractWithTelegramBot)) {
            throw new AuthorizationException(__('app.api_token_forbidden'));
        }
    }

    private function conversationFor(Account $account, Request $request): AiConversation
    {
        $trainer = $this->trainerFor($account, $request);

        $conversation = AiConversation::firstOrCreate(
            [
                'account_id' => $account->id,
                'channel' => 'dashboard_chat',
                'profile' => TelegramBotProfile::Owner->value,
                'user_id' => $request->user()->id,
                'status' => 'active',
            ],
            [
                'trainer_id' => $trainer?->id,
                'title' => __('app.owner_dashboard_chat_title'),
                'last_message_at' => now(),
            ],
        );

        if ($conversation->trainer_id !== $trainer?->id) {
            $conversation->forceFill(['trainer_id' => $trainer?->id])->save();
        }

        return $conversation;
    }

    private function trainerFor(Account $account, Request $request): ?Trainer
    {
        return $account->trainers()
            ->whereBelongsTo($request->user(), 'user')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function messagePayload(AiConversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(80)
            ->get()
            ->map(fn ($message): array => [
                'id' => $message->id,
                'role' => $message->role->value,
                'content' => $message->content,
                'metadata' => $message->metadata ?? [],
                'occurred_at' => $message->occurred_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingActionPayload(AiConversation $conversation): array
    {
        return $conversation->pendingActions()
            ->where('status', AiPendingAction::StatusPending)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get()
            ->map(fn (AiPendingAction $action): array => [
                'id' => $action->id,
                'action_name' => $action->action_name,
                'preview' => $action->preview ?? [],
                'expires_at' => $action->expires_at?->toIso8601String(),
            ])
            ->all();
    }

    private function ensureActionBelongsToAccount(Account $account, AiPendingAction $action): void
    {
        abort_unless((int) $action->account_id === (int) $account->id, 404);
    }
}
