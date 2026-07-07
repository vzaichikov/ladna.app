<?php

namespace App\Models;

use App\Enums\CustomerOtpSenderScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'allow_email_password', 'allow_otp', 'allow_google', 'otp_sender_scope', 'otp_provider', 'customer_sms_sender_scope', 'customer_sms_provider'])]
class CustomerAuthSetting extends Model
{
    protected $attributes = [
        'allow_email_password' => true,
        'allow_otp' => false,
        'allow_google' => false,
        'otp_sender_scope' => 'platform',
        'customer_sms_sender_scope' => 'platform',
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
            'otp_sender_scope' => CustomerOtpSenderScope::class,
            'customer_sms_sender_scope' => CustomerOtpSenderScope::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
