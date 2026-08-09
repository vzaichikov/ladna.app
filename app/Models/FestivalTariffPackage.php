<?php

namespace App\Models;

use Database\Factories\FestivalTariffPackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['subscription_plan_id', 'name', 'price_cents', 'currency', 'max_participants', 'max_tickets', 'is_active', 'sort_order'])]
class FestivalTariffPackage extends Model
{
    /** @use HasFactory<FestivalTariffPackageFactory> */
    use HasFactory;

    protected $attributes = ['currency' => 'UAH', 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'max_participants' => 'integer',
            'max_tickets' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(FestivalEditionPurchase::class);
    }
}
