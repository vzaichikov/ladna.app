<?php

namespace App\Support\CustomerNotifications;

use App\Models\Account;
use App\Models\Customer;
use App\Models\ScheduledClass;

class CustomerNotificationTextRenderer
{
    public function renderClassReminder(Account $account, ScheduledClass $scheduledClass, Customer $customer): string
    {
        $locale = $this->localeFor($account, $customer);
        $startsAt = $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone());

        return __('app.customer_notification_class_reminder_sms', [
            'studio' => $account->name,
            'date' => $startsAt->format('d.m.Y'),
            'time' => $startsAt->format('H:i'),
        ], $locale);
    }

    private function localeFor(Account $account, Customer $customer): string
    {
        $locales = array_keys(config('ladna.locales', []));

        foreach ([$customer->default_language, $account->default_language, config('app.locale')] as $locale) {
            if (is_string($locale) && in_array($locale, $locales, true)) {
                return $locale;
            }
        }

        return 'uk';
    }
}
