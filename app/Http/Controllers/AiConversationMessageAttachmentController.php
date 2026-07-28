<?php

namespace App\Http\Controllers;

use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AiConversationMessageAttachment;
use App\Models\PlatformAiSetting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AiConversationMessageAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        Account $account,
        AiConversationMessageAttachment $attachment,
    ): BinaryFileResponse {
        $this->authorize('view', $account);

        if (! PlatformAiSetting::ownerAssistantEnabled()) {
            abort(404);
        }

        if (! $account->userCan($request->user(), StudioPermission::InteractWithTelegramBot)) {
            throw new AuthorizationException(__('app.api_token_forbidden'));
        }

        abort_unless((int) $attachment->account_id === (int) $account->id, 404);

        $message = $attachment->message()
            ->whereHas('conversation', fn ($query) => $query
                ->where('account_id', $account->id)
                ->where('channel', 'dashboard_chat')
                ->where('user_id', $request->user()->id))
            ->first();

        abort_unless($message !== null, 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return response()->file($disk->path($attachment->path), [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
