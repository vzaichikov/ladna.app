<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'location_id', 'name', 'slug', 'description', 'capacity', 'color', 'is_active', 'rtsp_url', 'rtsp_enabled', 'people_counter_mask_polygons', 'people_counter_snapshot_path', 'people_counter_snapshot_width', 'people_counter_snapshot_height', 'people_counter_snapshot_taken_at'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
        'rtsp_enabled' => false,
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'rtsp_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rtsp_url' => 'encrypted',
            'rtsp_enabled' => 'boolean',
            'people_counter_mask_polygons' => 'array',
            'people_counter_snapshot_width' => 'integer',
            'people_counter_snapshot_height' => 'integer',
            'people_counter_snapshot_taken_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRtspEnabled(Builder $query): Builder
    {
        return $query->where('rtsp_enabled', true)
            ->whereNotNull('rtsp_url');
    }

    public function hasEnabledRtspCamera(): bool
    {
        return (bool) $this->rtsp_enabled && filled($this->rtsp_url);
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scheduleSeries(): HasMany
    {
        return $this->hasMany(ScheduleSeries::class);
    }

    public function scheduledClasses(): HasMany
    {
        return $this->hasMany(ScheduledClass::class);
    }

    public function peopleCounterSamples(): HasMany
    {
        return $this->hasMany(PeopleCounterSample::class);
    }

    public function scheduledClassPeopleCounts(): HasMany
    {
        return $this->hasMany(ScheduledClassPeopleCount::class);
    }

    public function classPassPlans(): BelongsToMany
    {
        return $this->belongsToMany(ClassPassPlan::class, 'class_pass_plan_room')
            ->withTimestamps();
    }
}
