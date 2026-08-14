<?php

namespace App\Console\Commands;

use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Jobs\SendFestivalNotification;
use App\Models\FestivalAnnouncement;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('festivals:dispatch-notifications {--limit=100}')]
#[Description('Fill and dispatch due Festival notifications and expire stale admission holds')]
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

        FestivalTicketOrder::query()->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $recovered = FestivalNotification::query()
            ->where('status', FestivalNotificationStatus::Sending->value)
            ->where('updated_at', '<=', now()->subMinutes(self::StaleSendingAfterMinutes))
            ->update([
                'status' => FestivalNotificationStatus::Failed->value,
                'failed_at' => now(),
                'failure_reason' => 'delivery_interrupted',
                'updated_at' => now(),
            ]);
        $notifications = FestivalNotification::query()
            ->whereIn('status', [FestivalNotificationStatus::Pending->value, FestivalNotificationStatus::Failed->value])
            ->where('attempts', '<', 5)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
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
