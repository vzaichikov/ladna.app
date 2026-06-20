<?php

namespace App\Models;

use Database\Factories\ActivityDirectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'name', 'slug', 'description', 'color', 'is_active'])]
class ActivityDirection extends Model
{
    /** @use HasFactory<ActivityDirectionFactory> */
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

    public function colorAccent(string $fallback = '#3B223F'): string
    {
        if (is_string($this->color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $this->color)) {
            return strtoupper($this->color);
        }

        return $fallback;
    }

    public function colorText(string $fallback = '#3B223F'): string
    {
        $color = ltrim($this->colorAccent($fallback), '#');
        $red = hexdec(substr($color, 0, 2));
        $green = hexdec(substr($color, 2, 2));
        $blue = hexdec(substr($color, 4, 2));
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance > 150 ? '#1E293B' : '#FFFFFF';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function classTypes(): HasMany
    {
        return $this->hasMany(ClassType::class);
    }

    public function classPassPlans(): BelongsToMany
    {
        return $this->belongsToMany(ClassPassPlan::class, 'class_pass_plan_activity_direction')
            ->withTimestamps();
    }
}
