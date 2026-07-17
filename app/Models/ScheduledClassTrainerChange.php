<?php

namespace App\Models;

use Database\Factories\ScheduledClassTrainerChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'scheduled_class_id', 'previous_trainer_id', 'new_trainer_id', 'previous_trainer_name', 'new_trainer_name', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role'])]
class ScheduledClassTrainerChange extends Model
{
    /** @use HasFactory<ScheduledClassTrainerChangeFactory> */
    use HasFactory;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scheduledClass(): BelongsTo
    {
        return $this->belongsTo(ScheduledClass::class);
    }
}
