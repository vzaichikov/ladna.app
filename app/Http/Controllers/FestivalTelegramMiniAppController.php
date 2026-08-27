<?php

namespace App\Http\Controllers;

use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Http\Requests\FestivalTelegramMiniAppRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalNotification;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Support\FestivalAuth\TelegramFestivalLoginTokenService;
use App\Support\Festivals\FestivalTelegramAuthorizationResolver;
use App\Support\Festivals\FestivalTelegramCheckoutHandoff;
use App\Support\Festivals\FestivalTelegramMiniAppData;
use App\Support\Telegram\TelegramMiniAppInitDataValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalTelegramMiniAppController extends Controller
{
    public function show(
        Request $request,
        string $accountSlug,
        string $seriesSlug,
        FestivalTelegramAuthorizationResolver $authorizations,
        FestivalTelegramMiniAppData $data,
    ): View {
        [$account, $series] = $this->context($request, $accountSlug, $seriesSlug);
        $installation = $authorizations->installation($series);
        abort_unless($installation, 404);

        return view('festivals.telegram.mini-app', [
            'account' => $account,
            'series' => $series,
            'installation' => $installation,
            'initialData' => $data->build($series),
            'labels' => $this->labels(),
        ]);
    }

    public function bootstrap(
        FestivalTelegramMiniAppRequest $request,
        string $accountSlug,
        string $seriesSlug,
        FestivalTelegramAuthorizationResolver $authorizations,
        TelegramMiniAppInitDataValidator $validator,
        FestivalTelegramMiniAppData $data,
    ): JsonResponse {
        [, $series] = $this->context($request, $accountSlug, $seriesSlug);
        $installation = $authorizations->installation($series);
        abort_unless($installation, 404);
        $validated = $validator->validate($request->validated('init_data'), $installation);
        $authorization = $authorizations->forTelegramUser($series, $installation, $validated['user']['id']);
        $sessionIdentity = [
            'series_id' => (int) $series->id,
            'installation_id' => (int) $installation->id,
            'telegram_user_id' => $validated['user']['id'],
        ];

        if ($request->session()->get('festival_telegram_identity') !== $sessionIdentity) {
            $request->session()->regenerate();
            $request->session()->put('festival_telegram_identity', $sessionIdentity);
        }

        return $this->privateJson([
            ...$data->build($series, $authorization),
            'contact_required' => $authorization === null,
            'bot_username' => $installation->bot_username,
        ]);
    }

    public function action(
        FestivalTelegramMiniAppRequest $request,
        string $accountSlug,
        string $seriesSlug,
        FestivalTelegramAuthorizationResolver $authorizations,
        TelegramMiniAppInitDataValidator $validator,
        TelegramFestivalLoginTokenService $loginTokens,
        FestivalTelegramCheckoutHandoff $checkoutHandoff,
    ): JsonResponse {
        [, $series] = $this->context($request, $accountSlug, $seriesSlug);
        $installation = $authorizations->installation($series);
        abort_unless($installation, 404);
        $validated = $validator->validate($request->validated('init_data'), $installation);
        $authorization = $authorizations->forTelegramUser($series, $installation, $validated['user']['id']);
        abort_unless($authorization, 401);
        $action = (string) $request->validated('action');
        $targetId = $request->validated('target_id');
        $targetId = $targetId !== null ? (int) $targetId : null;

        if (in_array($action, ['dashboard', 'profile', 'entries', 'entry', 'create_entry'], true)) {
            $registrant = $authorizations->linkedPortalUser($authorization, FestivalPortalRole::Registrant);
            abort_unless($registrant, 403);

            return $this->privateJson([
                'url' => $loginTokens->issueRegistrantUrl($series, $authorization, $registrant, $action, $targetId),
            ]);
        }

        if ($action === 'ticket_checkout') {
            $edition = FestivalEdition::query()
                ->whereKey($targetId)
                ->where('account_id', $series->account_id)
                ->where('festival_series_id', $series->id)
                ->published()
                ->firstOrFail();

            return $this->privateJson(['url' => $checkoutHandoff->issueUrl($series, $edition, $authorization)]);
        }

        if ($action === 'ticket_order') {
            $guest = $authorizations->linkedPortalUser($authorization, FestivalPortalRole::Guest);
            abort_unless($guest, 403);
            $order = FestivalTicketOrder::query()
                ->whereKey($targetId)
                ->where('account_id', $series->account_id)
                ->where('festival_portal_user_id', $guest->id)
                ->whereHas('edition', fn ($query) => $query->where('festival_series_id', $series->id))
                ->firstOrFail();

            return $this->privateJson(['url' => $loginTokens->issueOrderUrl($series, $authorization, $order)]);
        }

        abort(422);
    }

    public function unlink(
        FestivalTelegramMiniAppRequest $request,
        string $accountSlug,
        string $seriesSlug,
        FestivalTelegramAuthorizationResolver $authorizations,
        TelegramMiniAppInitDataValidator $validator,
    ): JsonResponse {
        [, $series] = $this->context($request, $accountSlug, $seriesSlug);
        $installation = $authorizations->installation($series);
        abort_unless($installation, 404);
        $validated = $validator->validate($request->validated('init_data'), $installation);
        $authorization = $authorizations->forTelegramUser($series, $installation, $validated['user']['id']);
        abort_unless($authorization, 401);

        DB::transaction(function () use ($authorization): void {
            $authorization->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked,
                'revoked_at' => now(),
            ])->save();
            FestivalNotification::query()
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->where('channel', FestivalNotificationChannel::Telegram->value)
                ->whereIn('status', [FestivalNotificationStatus::Pending->value, FestivalNotificationStatus::Failed->value])
                ->update([
                    'status' => FestivalNotificationStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'failure_reason' => 'festival_telegram_authorization_revoked',
                ]);
        });
        $request->session()->forget('festival_telegram_identity');

        return $this->privateJson(['unlinked' => true]);
    }

    /** @return array{Account, FestivalSeries} */
    private function context(Request $request, string $accountSlug, string $seriesSlug): array
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);
        $series = FestivalSeries::query()
            ->whereBelongsTo($account)
            ->where('slug', $seriesSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return [$account, $series];
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload): JsonResponse
    {
        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return [
            'authorization_title' => __('app.festival_telegram_authorization_title'),
            'authorization_help' => __('app.festival_telegram_authorization_help'),
            'share_phone' => __('app.festival_telegram_share_phone'),
            'authorization_waiting' => __('app.festival_telegram_authorization_waiting'),
            'authorization_ready' => __('app.festival_telegram_authorization_ready'),
            'open_ladna' => __('app.festival_telegram_open_ladna'),
            'new_application' => __('app.festival_new_application'),
            'additional_application_confirmation' => __('app.festival_additional_application_confirmation'),
            'my_applications_count' => __('app.festival_my_applications_count', ['count' => '__count__']),
            'tickets' => __('app.festival_tickets'),
            'back' => __('app.back'),
            'calendar' => __('app.festival_telegram_calendar'),
            'my_festival' => __('app.festival_telegram_my_festival'),
            'my_tickets' => __('app.festival_telegram_my_tickets'),
            'statistics' => __('app.statistics'),
            'preferences' => __('app.notification_preferences'),
            'contacts' => __('app.contacts'),
            'participants' => __('app.festival_participants'),
            'applications' => __('app.festival_applications'),
            'accepted' => __('app.festival_status_accepted'),
            'profile_incomplete' => __('app.festival_telegram_profile_incomplete'),
            'open_profile' => __('app.profile'),
            'no_items' => __('app.no_records'),
            'live' => __('app.festival_telegram_period_live'),
            'upcoming' => __('app.festival_telegram_period_upcoming'),
            'previous' => __('app.festival_telegram_period_previous'),
            'timeline' => __('app.festival_timeline'),
            'schedule' => __('app.festival_schedule'),
            'results' => __('app.festival_results'),
            'documents' => __('app.festival_documents'),
            'enabled' => __('app.enabled'),
            'disabled' => __('app.disabled'),
            'unlink' => __('app.festival_telegram_unlink'),
            'unlink_confirm' => __('app.festival_telegram_unlink_confirm'),
            'generic_error' => __('app.festival_telegram_generic_error'),
            'outside_telegram' => __('app.festival_telegram_open_inside_bot'),
        ];
    }
}
