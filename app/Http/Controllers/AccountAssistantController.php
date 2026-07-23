<?php

namespace App\Http\Controllers;

use App\Enums\AiConversationMessageRole;
use App\Enums\StudioAiDisposition;
use App\Enums\StudioPermission;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\PlatformAiSetting;
use App\Models\Trainer;
use App\Support\Ai\StudioAiActionInput;
use App\Support\Ai\StudioAiInference;
use App\Support\Ai\StudioAiResult;
use App\Support\Ai\StudioAssistantActionExecutor;
use App\Support\Ai\StudioAssistantActionPlan;
use App\Support\Ai\StudioAssistantActionPlanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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

    public function store(
        Request $request,
        Account $account,
        StudioAiInference $inference,
        StudioAssistantActionPlanner $planner,
    ): JsonResponse|StreamedResponse {
        $this->authorizeAssistant($request, $account);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $conversation = $this->conversationFor($account, $request);
        $text = trim((string) $validated['message']);
        $trainer = $this->trainerFor($account, $request);

        $currentMessage = $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => $text,
            'occurred_at' => now(),
        ]);

        if (Str::contains((string) $request->header('Accept'), 'application/x-ndjson')) {
            return response()->stream(function () use (
                $account,
                $request,
                $inference,
                $planner,
                $conversation,
                $currentMessage,
                $text,
                $trainer,
            ): void {
                try {
                    $payload = $this->processAssistantMessage(
                        $account,
                        $request,
                        $inference,
                        $planner,
                        $conversation,
                        $currentMessage,
                        $text,
                        $trainer,
                        fn (string $statusKey) => $this->writeNdjson([
                            'type' => 'status',
                            'key' => $statusKey,
                            'message' => __('app.'.$statusKey),
                        ]),
                    );
                    $this->writeNdjson([
                        'type' => 'result',
                        'payload' => $payload,
                    ]);
                } catch (Throwable $throwable) {
                    report($throwable);
                    $this->writeNdjson([
                        'type' => 'error',
                        'message' => __('app.assistant_chat_error'),
                    ]);
                }
            }, 200, [
                'Content-Type' => 'application/x-ndjson; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, no-transform',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        return response()->json($this->processAssistantMessage(
            $account,
            $request,
            $inference,
            $planner,
            $conversation,
            $currentMessage,
            $text,
            $trainer,
        ));
    }

    /**
     * @param  callable(string): mixed|null  $progress
     * @return array<string, mixed>
     */
    private function processAssistantMessage(
        Account $account,
        Request $request,
        StudioAiInference $inference,
        StudioAssistantActionPlanner $planner,
        AiConversation $conversation,
        AiConversationMessage $currentMessage,
        string $text,
        ?Trainer $trainer,
        ?callable $progress = null,
    ): array {
        $this->progress($progress, 'assistant_status_checking_database');

        if ($account->isReadOnlyDemo()) {
            $this->storeInferenceResponse(
                $account,
                $conversation,
                $inference->respond(
                    $account,
                    $text,
                    conversation: $conversation,
                    currentMessage: $currentMessage,
                    actorUser: $request->user(),
                    actorTrainer: $trainer,
                    beforeProviderRequest: $progress,
                ),
            );
        } else {
            $plan = $this->exactActionPlan($account, $request, $conversation, $text, $trainer, $planner);
            $aiResult = null;

            if (! $plan) {
                $aiResult = $inference->respond(
                    $account,
                    $text,
                    conversation: $conversation,
                    currentMessage: $currentMessage,
                    actorUser: $request->user(),
                    actorTrainer: $trainer,
                    beforeProviderRequest: $progress,
                );

                if ($aiResult->isAction()) {
                    $plan = $planner->plan(
                        $account,
                        $request->user(),
                        $trainer,
                        $conversation,
                        $aiResult->disposition,
                        $aiResult->actionInput,
                    );
                }
            }

            if ($plan?->handled) {
                $this->progress(
                    $progress,
                    $plan->pendingAction ? 'assistant_status_preparing_action' : 'assistant_status_checking_database',
                );
                $this->storeActionPlanResponse($account, $conversation, $plan, $aiResult);
            } else {
                $this->storeInferenceResponse(
                    $account,
                    $conversation,
                    $aiResult?->isAction()
                        ? StudioAiResult::fallback('invalid_ai_action')
                        : ($aiResult ?? StudioAiResult::fallback('invalid_ai_response')),
                );
            }
        }

        $conversation->update(['last_message_at' => now()]);

        return [
            'messages' => $this->messagePayload($conversation->refresh()),
            'pending_actions' => $this->pendingActionPayload($conversation),
        ];
    }

    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAssistant($request, $account);

        $conversation = $this->activeConversationQuery($account, $request)->first();

        if ($conversation) {
            DB::transaction(function () use ($conversation): void {
                $conversation->pendingActions()
                    ->where('status', AiPendingAction::StatusPending)
                    ->update([
                        'status' => AiPendingAction::StatusCancelled,
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);

                $conversation->forceFill([
                    'status' => AiConversation::StatusCleared,
                    'last_message_at' => now(),
                ])->save();
            });
        }

        $conversation = $this->conversationFor($account, $request);

        return response()->json([
            'messages' => $this->messagePayload($conversation),
            'pending_actions' => $this->pendingActionPayload($conversation),
        ]);
    }

    public function confirm(Request $request, Account $account, AiPendingAction $action, StudioAssistantActionExecutor $executor): JsonResponse
    {
        $this->authorizeAssistant($request, $account);
        abort_if($account->isReadOnlyDemo(), 423, __('app.demo_readonly_message'));
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
        abort_if($account->isReadOnlyDemo(), 423, __('app.demo_readonly_message'));
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

    private function storeInferenceResponse(Account $account, AiConversation $conversation, StudioAiResult $result): void
    {
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
                'help_sources' => $result->helpSources,
                'disposition' => $result->disposition->value,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function storeActionPlanResponse(
        Account $account,
        AiConversation $conversation,
        StudioAssistantActionPlan $plan,
        ?StudioAiResult $result = null,
    ): void {
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::Assistant->value,
            'content' => $plan->message ?? ($plan->pendingAction
                ? __('app.assistant_pending_action_created')
                : ''),
            'metadata' => [
                'used_ai' => $result?->usedAi ?? false,
                'provider' => $result?->provider,
                'model' => $result?->model,
                'disposition' => $result?->disposition->value,
                ...($plan->pendingAction ? ['pending_action_id' => $plan->pendingAction->id] : []),
                ...$plan->metadata,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function exactActionPlan(
        Account $account,
        Request $request,
        AiConversation $conversation,
        string $text,
        ?Trainer $trainer,
        StudioAssistantActionPlanner $planner,
    ): ?StudioAssistantActionPlan {
        $normalized = Str::of($text)->lower()->squish()->toString();

        if (preg_match('/^\/help(?:@\w+)?$/u', $normalized) === 1) {
            return StudioAssistantActionPlan::message(__('app.telegram_owner_help'));
        }

        if (preg_match('/^\/book(?:@\w+)?$/u', $normalized) === 1) {
            return $planner->startGroupBookingDialog($account, $request->user(), $trainer, $conversation);
        }

        if (preg_match('/^\/(?:cancel_booking|cancel)(?:@\w+)?$/u', $normalized) === 1) {
            return $planner->plan(
                $account,
                $request->user(),
                $trainer,
                $conversation,
                StudioAiDisposition::CancelDialog,
                new StudioAiActionInput,
            );
        }

        return $planner->planExactDialogOption(
            $account,
            $request->user(),
            $trainer,
            $conversation,
            $text,
        );
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

        $conversation = $this->activeConversationQuery($account, $request)->first();

        if (! $conversation) {
            $conversation = AiConversation::create([
                'account_id' => $account->id,
                'channel' => 'dashboard_chat',
                'profile' => TelegramBotProfile::Owner->value,
                'user_id' => $request->user()->id,
                'status' => AiConversation::StatusActive,
                'trainer_id' => $trainer?->id,
                'title' => __('app.owner_dashboard_chat_title'),
                'last_message_at' => now(),
            ]);
        }

        if ($conversation->trainer_id !== $trainer?->id) {
            $conversation->forceFill(['trainer_id' => $trainer?->id])->save();
        }

        return $conversation;
    }

    /**
     * @return Builder<AiConversation>
     */
    private function activeConversationQuery(Account $account, Request $request): Builder
    {
        return AiConversation::query()
            ->where('account_id', $account->id)
            ->where('channel', 'dashboard_chat')
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('user_id', $request->user()->id)
            ->where('status', AiConversation::StatusActive);
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

    /**
     * @param  callable(string): mixed|null  $progress
     */
    private function progress(?callable $progress, string $statusKey): void
    {
        if ($progress) {
            $progress($statusKey);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function writeNdjson(array $event): void
    {
        echo json_encode(
            $event,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        )."\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
