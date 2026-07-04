<?php

namespace App\Models;

use Database\Factories\CustomerPurchaseCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPurchaseCorrection extends Model
{
    /** @use HasFactory<CustomerPurchaseCorrectionFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'customer_purchase_id',
        'previous_location_id',
        'new_location_id',
        'previous_amount_cents',
        'new_amount_cents',
        'previous_paid_at',
        'new_paid_at',
        'actor_user_id',
        'actor_trainer_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_paid_at' => 'datetime',
            'new_paid_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customerPurchase(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchase::class);
    }

    public function previousLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'previous_location_id');
    }

    public function newLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'new_location_id');
    }
}
