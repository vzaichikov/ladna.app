<?php

namespace App\Models;

use App\Enums\TrainerSubstitutionMode;
use Database\Factories\TrainerSubstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'replaced_trainer_id', 'substitute_trainer_id', 'location_id', 'room_id', 'mode', 'date_from', 'date_to', 'scheduled_class_ids', 'class_type_ids', 'replaced_trainer_name', 'substitute_trainer_name', 'location_name', 'room_name'])]
class TrainerSubstitution extends Model
{
    /** @use HasFactory<TrainerSubstitutionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => TrainerSubstitutionMode::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'scheduled_class_ids' => 'array',
            'class_type_ids' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function replacedTrainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'replaced_trainer_id');
    }

    public function substituteTrainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'substitute_trainer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function isClassesMode(): bool
    {
        return $this->mode === TrainerSubstitutionMode::Classes;
    }

    public function isPeriodMode(): bool
    {
        return $this->mode === TrainerSubstitutionMode::Period;
    }
}
