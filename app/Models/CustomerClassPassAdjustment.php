<?php

namespace App\Models;

use App\Enums\CustomerClassPassAdjustmentType;
use Database\Factories\CustomerClassPassAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'customer_class_pass_id', 'user_id', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role', 'adjustment_type', 'sessions_delta', 'previous_sessions_count', 'new_sessions_count', 'days_delta', 'previous_validity_days', 'new_validity_days', 'previous_status', 'new_status', 'freeze_started_at', 'freeze_finished_at', 'freeze_days_count', 'reason'])]
class CustomerClassPassAdjustment extends Model
{
    /** @use HasFactory<CustomerClassPassAdjustmentFactory> */
    use HasFactory;

    protected $attributes = [
        'adjustment_type' => 'sessions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'adjustment_type' => CustomerClassPassAdjustmentType::class,
            'freeze_started_at' => 'datetime',
            'freeze_finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
