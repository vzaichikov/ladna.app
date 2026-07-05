<?php

namespace App\Models;

use Database\Factories\UnknownPresenceIntervalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'location_id', 'room_id', 'started_at', 'ended_at', 'sample_count', 'peak_detected_count'])]
class UnknownPresenceInterval extends Model
{
    /** @use HasFactory<UnknownPresenceIntervalFactory> */
    use HasFactory;

    protected $attributes = [
        'sample_count' => 0,
        'peak_detected_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'sample_count' => 'integer',
            'peak_detected_count' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(PeopleCounterSample::class);
    }
}
