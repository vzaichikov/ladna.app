<?php

namespace App\Actions\Festivals;

use App\Models\FestivalWorkflowStep;
use Illuminate\Support\Facades\DB;

class UpdateFestivalWorkflowStepCompletionNotifications
{
    /** @param array<string, array<string, string|null>> $notifications */
    public function execute(FestivalWorkflowStep $step, array $notifications): FestivalWorkflowStep
    {
        return DB::transaction(function () use ($step, $notifications): FestivalWorkflowStep {
            $step = FestivalWorkflowStep::query()->whereKey($step->id)->lockForUpdate()->firstOrFail();
            $config = is_array($step->config) ? $step->config : [];
            $completionNotifications = [];

            foreach (['uk', 'en'] as $locale) {
                foreach (['email', 'sms', 'telegram'] as $channel) {
                    $value = trim((string) data_get($notifications, $locale.'.'.$channel, ''));

                    if ($value !== '') {
                        $completionNotifications[$locale][$channel] = $value;
                    }
                }
            }

            if ($completionNotifications === []) {
                unset($config['completion_notifications']);
            } else {
                $config['completion_notifications'] = $completionNotifications;
            }

            $step->forceFill(['config' => $config === [] ? null : $config])->save();

            return $step->refresh();
        }, 3);
    }
}
