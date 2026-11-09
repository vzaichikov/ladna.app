<?php

namespace App\Console\Commands;

use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Jobs\SendFestivalNotification;
use App\Models\FestivalAnnouncement;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('festivals:dispatch-notifications {--limit=100}')]
#[Description('Fill and dispatch due Festival notifications')]
class DispatchFestivalNotifications extends Command
{
    private const int StaleSendingAfterMinutes = 5;

    public function handle(FestivalNotificationOutbox $outbox): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $announcements = FestivalAnnouncement::query()->with('edition')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->limit($limit)->get();
        foreach ($announcements as $announcement) {
            $users = FestivalPortalUser::query()
                ->where('account_id', $announcement->account_id)
                ->where('role', 'registrant')
                ->where('is_active', true)
                ->whereHas('entries', fn ($query) => $query->where('festival_edition_id', $announcement->festival_edition_id))
                ->get();
            foreach ($users as $portalUser) {
                $outbox->queue($portalUser, $announcement->edition, FestivalNotificationType::Announcement, ['subject' => $announcement->subject, 'body' => $announcement->body], dedupeSuffix: 'announcement:'.$announcement->id);
            }
            $announcement->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        }

        $recovered = FestivalNotification::query()
            ->where('status', FestivalNotificationStatus::Sending->value)
            ->where('channel', FestivalNotificationChannel::Sms->value)
            ->where('updated_at', '<=', now()->subMinutes(self::StaleSendingAfterMinutes))
            ->update([
                'status' => FestivalNotificationStatus::Failed->value,
                'failed_at' => now(),
                'failure_reason' => 'sms_delivery_outcome_unknown',
                'updated_at' => now(),
            ]);
        $recovered += FestivalNotification::query()
            ->where('status', FestivalNotificationStatus::Sending->value)
            ->where('channel', '!=', FestivalNotificationChannel::Sms->value)
            ->where('updated_at', '<=', now()->subMinutes(self::StaleSendingAfterMinutes))
            ->update([
                'status' => FestivalNotificationStatus::Failed->value,
                'failed_at' => now(),
                'failure_reason' => 'delivery_interrupted',
                'updated_at' => now(),
            ]);
        $notifications = FestivalNotification::query()
            ->readyForDelivery()
            ->orderBy('id')
            ->limit($limit)
            ->get();
        foreach ($notifications as $notification) {
            SendFestivalNotification::dispatch($notification->id);
        }

        $this->info("Announcements: {$announcements->count()}; recovered: {$recovered}; notifications: {$notifications->count()}.");

        return self::SUCCESS;
    }
}
