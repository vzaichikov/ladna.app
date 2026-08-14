<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\FestivalNotificationType;
use App\Http\Requests\FestivalAnnouncementRequest;
use App\Models\Account;
use App\Models\FestivalAnnouncement;
use App\Models\FestivalEdition;
use App\Models\FestivalNotificationPreference;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalPortalUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FestivalAnnouncementController extends Controller
{
    public function updateSettings(Request $request, Account $account): RedirectResponse
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        foreach (FestivalNotificationType::cases() as $type) {
            FestivalNotificationSetting::query()->updateOrCreate(
                ['account_id' => $account->id, 'type' => $type->value],
                [
                    'is_optional' => $type->isOptional(),
                    'is_enabled' => true,
                    'send_sms' => $request->boolean('sms.'.$type->value),
                    'notify_owner_telegram' => $request->boolean('owner_telegram.'.$type->value),
                ],
            );
        }

        return back()->with('status', __('app.festival_notification_settings_saved'));
    }

    public function updatePreferences(Request $request, string $accountSlug): RedirectResponse
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id, 404);
        foreach (FestivalNotificationType::cases() as $type) {
            if (! $type->isOptional()) {
                continue;
            }
            FestivalNotificationPreference::query()->updateOrCreate(['festival_portal_user_id' => $portalUser->id, 'type' => $type->value], ['account_id' => $account->id, 'is_enabled' => $request->boolean('types.'.$type->value)]);
        }

        return back()->with('status', __('app.festival_notification_preferences_saved'));
    }

    public function store(FestivalAnnouncementRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalNotificationOutbox $outbox): RedirectResponse
    {
        abort_unless($festivalEdition->account_id === $account->id, 404);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validated();
        $scheduledAt = filled($data['scheduled_at'] ?? null)
            ? CarbonImmutable::parse((string) $data['scheduled_at'], $festivalEdition->timezone)->utc()
            : now();
        $announcement = FestivalAnnouncement::query()->create([
            'account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id,
            'subject' => $data['subject'], 'body' => $data['body'], 'status' => $scheduledAt->isFuture() ? 'scheduled' : 'sending',
            'scheduled_at' => $scheduledAt, 'created_by' => $request->user()->id,
        ]);

        if ($announcement->scheduled_at->lessThanOrEqualTo(now())) {
            $this->dispatch($announcement, $outbox);
        }

        return redirect()->route('dashboard.accounts.festivals.communication', [$account, $festivalEdition, 'tab' => 'announcements'])->with('status', __('app.festival_announcement_saved'));
    }

    public function dispatch(FestivalAnnouncement $announcement, FestivalNotificationOutbox $outbox): int
    {
        $users = FestivalPortalUser::query()->where('account_id', $announcement->account_id)
            ->where('role', 'registrant')
            ->where('is_active', true)
            ->whereHas('entries', fn ($query) => $query->where('festival_edition_id', $announcement->festival_edition_id))
            ->get();
        foreach ($users as $portalUser) {
            $outbox->queue($portalUser, $announcement->edition, FestivalNotificationType::Announcement, [
                'subject' => $announcement->subject,
                'body' => $announcement->body,
            ], dedupeSuffix: 'announcement:'.$announcement->id);
        }
        $announcement->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        return $users->count();
    }
}
