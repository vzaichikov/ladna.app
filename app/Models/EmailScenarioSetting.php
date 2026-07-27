<?php

namespace App\Models;

use App\Enums\EmailScenario;
use Database\Factories\EmailScenarioSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scenario', 'is_enabled'])]
class EmailScenarioSetting extends Model
{
    /** @use HasFactory<EmailScenarioSettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scenario' => EmailScenario::class,
            'is_enabled' => 'boolean',
        ];
    }
}
