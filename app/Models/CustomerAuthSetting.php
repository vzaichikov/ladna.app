<?php

namespace App\Models;

use App\Enums\SmsSendingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'allow_email_password', 'allow_otp', 'allow_google', 'sms_sending_mode', 'sms_provider'])]
class CustomerAuthSetting extends Model
{
    protected $attributes = [
        'allow_email_password' => true,
        'allow_otp' => false,
        'allow_google' => false,
        'sms_sending_mode' => 'disabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_email_password' => 'boolean',
            'allow_otp' => 'boolean',
            'allow_google' => 'boolean',
            'sms_sending_mode' => SmsSendingMode::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
