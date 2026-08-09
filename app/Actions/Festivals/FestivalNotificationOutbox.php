<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalNotificationType;
use App\Jobs\SendFestivalNotification;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationPreference;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalPortalUser;
use Illuminate\Support\Facades\DB;

class FestivalNotificationOutbox
{
    /** @param array<string, mixed> $payload */
    public function queueForEntry(FestivalEntry $entry, string|FestivalNotificationType $type, array $payload, ?string $dedupeSuffix = null): ?FestivalNotification
    {
        $entry->loadMissing(['portalUser', 'edition']);

        return $this->queue(
            portalUser: $entry->portalUser,
            edition: $entry->edition,
            type: $type,
            payload: $payload,
            entry: $entry,
            dedupeSuffix: $dedupeSuffix,
        );
    }

    /** @param array<string, mixed> $payload */
    public function queue(FestivalPortalUser $portalUser, FestivalEdition $edition, string|FestivalNotificationType $type, array $payload, ?FestivalEntry $entry = null, ?string $dedupeSuffix = null): ?FestivalNotification
    {
        $type = $type instanceof FestivalNotificationType ? $type : FestivalNotificationType::from($type);
        abort_unless($portalUser->account_id === $edition->account_id, 404);

        if (! $this->isEnabled($portalUser, $type)) {
            return null;
        }

        $dedupeKey = implode(':', [
            $type->value,
            $edition->id,
            $entry?->id ?? 0,
            $portalUser->id,
            $dedupeSuffix ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);

        $notification = FestivalNotification::query()->firstOrCreate([
            'dedupe_key' => $dedupeKey,
        ], [
            'account_id' => $edition->account_id,
            'festival_portal_user_id' => $portalUser->id,
            'festival_edition_id' => $edition->id,
            'festival_entry_id' => $entry?->id,
            'type' => $type,
            'recipient_email' => $portalUser->email,
            'recipient_name' => $portalUser->displayName(),
            'payload' => $payload,
            'available_at' => now(),
        ]);

        if ($notification->wasRecentlyCreated) {
            DB::afterCommit(fn () => SendFestivalNotification::dispatch($notification->id));
        }

        return $notification;
    }

    private function isEnabled(FestivalPortalUser $portalUser, FestivalNotificationType $type): bool
    {
        if (! $type->isOptional()) {
            return true;
        }

        $accountEnabled = FestivalNotificationSetting::query()
            ->where('account_id', $portalUser->account_id)
            ->where('type', $type->value)
            ->where('is_enabled', true)
            ->exists();
        $recipientEnabled = FestivalNotificationPreference::query()
            ->where('festival_portal_user_id', $portalUser->id)
            ->where('type', $type->value)
            ->where('is_enabled', true)
            ->exists();

        return $accountEnabled && $recipientEnabled;
    }
}
