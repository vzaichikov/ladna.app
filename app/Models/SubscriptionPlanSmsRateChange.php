<?php

namespace App\Models;

use Database\Factories\SubscriptionPlanSmsRateChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['subscription_plan_id', 'actor_user_id', 'old_sms_segment_price_cents', 'new_sms_segment_price_cents'])]
class SubscriptionPlanSmsRateChange extends Model
{
    /** @use HasFactory<SubscriptionPlanSmsRateChangeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Subscription plan SMS rate changes are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Subscription plan SMS rate changes cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_sms_segment_price_cents' => 'integer',
            'new_sms_segment_price_cents' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
