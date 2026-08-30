<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FestivalWorkflowStepCompletionNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    protected function prepareForValidation(): void
    {
        $notifications = $this->input('completion_notifications', []);

        foreach (['uk', 'en'] as $locale) {
            foreach (['email', 'sms', 'telegram'] as $channel) {
                $value = data_get($notifications, $locale.'.'.$channel);

                if (is_string($value)) {
                    data_set($notifications, $locale.'.'.$channel, trim($value));
                }
            }
        }

        $this->merge(['completion_notifications' => $notifications]);
    }

    public function rules(): array
    {
        return [
            'completion_notifications' => ['required', 'array:uk,en'],
            'completion_notifications.uk' => ['required', 'array:email,sms,telegram'],
            'completion_notifications.en' => ['required', 'array:email,sms,telegram'],
            'completion_notifications.uk.email' => ['nullable', 'string', 'max:5000'],
            'completion_notifications.uk.sms' => ['nullable', 'string', 'max:1000'],
            'completion_notifications.uk.telegram' => ['nullable', 'string', 'max:3000'],
            'completion_notifications.en.email' => ['nullable', 'string', 'max:5000'],
            'completion_notifications.en.sms' => ['nullable', 'string', 'max:1000'],
            'completion_notifications.en.telegram' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['uk', 'en'] as $locale) {
                foreach (['email', 'sms', 'telegram'] as $channel) {
                    $field = 'completion_notifications.'.$locale.'.'.$channel;
                    $value = $this->input($field);

                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    preg_match_all('/%[\p{L}\p{N}_-]+%/u', $value, $matches);
                    $unknownPlaceholders = array_values(array_diff(array_unique($matches[0]), ['%name%', '%category%']));

                    if ($unknownPlaceholders !== []) {
                        $validator->errors()->add($field, __('app.festival_completion_notification_unknown_placeholders', [
                            'placeholders' => implode(', ', $unknownPlaceholders),
                        ]));
                    }
                }
            }
        }];
    }
}
