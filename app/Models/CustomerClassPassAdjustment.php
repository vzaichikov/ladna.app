<?php

namespace App\Models;

use Database\Factories\CustomerClassPassAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'customer_class_pass_id', 'user_id', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role', 'sessions_delta', 'previous_sessions_count', 'new_sessions_count', 'reason'])]
class CustomerClassPassAdjustment extends Model
{
    /** @use HasFactory<CustomerClassPassAdjustmentFactory> */
    use HasFactory;

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
