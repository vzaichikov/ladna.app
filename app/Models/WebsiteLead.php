<?php

namespace App\Models;

use App\Enums\WebsiteLeadStatus;
use Database\Factories\WebsiteLeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'customer_id', 'class_booking_id', 'name', 'phone', 'source_page', 'status', 'notes', 'converted_at'])]
class WebsiteLead extends Model
{
    /** @use HasFactory<WebsiteLeadFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'new',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebsiteLeadStatus::class,
            'converted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }
}
