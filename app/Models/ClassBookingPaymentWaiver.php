<?php

namespace App\Models;

use Database\Factories\ClassBookingPaymentWaiverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['account_id', 'class_booking_id', 'customer_class_pass_id', 'payment_due_kind', 'amount_cents', 'currency', 'customer_name', 'scheduled_class_title', 'scheduled_class_starts_at', 'scheduled_class_timezone', 'location_name', 'room_name', 'customer_class_pass_code', 'reason', 'waived_at', 'waived_by_actor_user_id', 'waived_by_actor_trainer_id', 'waived_by_actor_name', 'waived_by_actor_email', 'waived_by_actor_role', 'unwaived_at', 'unwaive_reason', 'unwaived_by_actor_user_id', 'unwaived_by_actor_trainer_id', 'unwaived_by_actor_name', 'unwaived_by_actor_email', 'unwaived_by_actor_role'])]
class ClassBookingPaymentWaiver extends Model
{
    /** @use HasFactory<ClassBookingPaymentWaiverFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'scheduled_class_starts_at' => 'datetime',
            'waived_at' => 'datetime',
            'unwaived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $waiver): void {
            $allowedChanges = [
                'unwaived_at',
                'unwaive_reason',
                'unwaived_by_actor_user_id',
                'unwaived_by_actor_trainer_id',
                'unwaived_by_actor_name',
                'unwaived_by_actor_email',
                'unwaived_by_actor_role',
                'updated_at',
            ];

            if ($waiver->getOriginal('unwaived_at') !== null
                || $waiver->unwaived_at === null
                || blank($waiver->unwaive_reason)
                || array_diff(array_keys($waiver->getDirty()), $allowedChanges) !== []) {
                throw new LogicException('Booking payment waivers are immutable except for the audited unwaive transition.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Booking payment waivers cannot be deleted.'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unwaived_at');
    }

    public function scopeUnwaived(Builder $query): Builder
    {
        return $query->whereNotNull('unwaived_at');
    }

    public function isActive(): bool
    {
        return $this->unwaived_at === null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }

    public function customerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class);
    }
}
