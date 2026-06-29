<?php

namespace App\Models;

use App\Enums\AiProvider;
use Database\Factories\PlatformAiSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['owner_ai_assistant_enabled', 'active_provider', 'active_model', 'bot_display_name', 'internal_instructions'])]
class PlatformAiSetting extends Model
{
    /** @use HasFactory<PlatformAiSettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_ai_assistant_enabled' => 'boolean',
            'active_provider' => AiProvider::class,
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'owner_ai_assistant_enabled' => false,
            'bot_display_name' => 'Ladna assistant',
        ]);
    }

    public static function ownerAssistantEnabled(): bool
    {
        $setting = self::query()->first();

        return (bool) $setting?->owner_ai_assistant_enabled;
    }
}
