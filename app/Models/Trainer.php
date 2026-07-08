<?php

namespace App\Models;

use Database\Factories\TrainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['account_id', 'user_id', 'trainer_type_id', 'name', 'slug', 'email', 'phone', 'bio', 'photo_path', 'is_active'])]
class Trainer extends Model
{
    /** @use HasFactory<TrainerFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainerType(): BelongsTo
    {
        return $this->belongsTo(TrainerType::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'trainer_location')
            ->withPivot('account_id')
            ->withTimestamps();
    }

    public function privateTimeframes(): HasMany
    {
        return $this->hasMany(TrainerPrivateTimeframe::class);
    }

    public function scheduledClasses(): HasMany
    {
        return $this->hasMany(ScheduledClass::class);
    }

    public function scheduleSeries(): HasMany
    {
        return $this->hasMany(ScheduleSeries::class);
    }

    public function substitutionsAsReplacedTrainer(): HasMany
    {
        return $this->hasMany(TrainerSubstitution::class, 'replaced_trainer_id');
    }

    public function substitutionsAsSubstituteTrainer(): HasMany
    {
        return $this->hasMany(TrainerSubstitution::class, 'substitute_trainer_id');
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
