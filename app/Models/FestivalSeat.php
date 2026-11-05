<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_admission_type_id', 'floor_key', 'section', 'group_kind', 'group_label', 'seat_number', 'x', 'y', 'accessibility', 'sightline', 'is_blocked', 'hold_key', 'hold_expires_at'])]
#[Hidden(['hold_key', 'hold_expires_at'])]
class FestivalSeat extends Model
{
    protected $attributes = ['is_blocked' => false];

    protected function casts(): array
    {
        return ['is_blocked' => 'boolean', 'x' => 'integer', 'y' => 'integer', 'hold_expires_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function admissionType(): BelongsTo
    {
        return $this->belongsTo(FestivalAdmissionType::class, 'festival_admission_type_id');
    }

    public function orderSeats(): HasMany
    {
        return $this->hasMany(FestivalTicketOrderSeat::class);
    }

    public function label(?string $locale = null): string
    {
        $floor = collect($this->edition->seating_floors)->firstWhere('id', $this->floor_key);
        $group = __("app.festival_seating.{$this->group_kind}_label", ['label' => $this->group_label], $locale);

        return implode(' · ', array_filter([
            $floor['label'] ?? null,
            $this->section,
            $group,
            __('app.festival_seating.seat_label', ['number' => $this->seat_number], $locale),
        ], fn (?string $part): bool => filled($part)));
    }
}
