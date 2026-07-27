<?php

namespace App\Http\Requests;

use App\Enums\EmailScenario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateEmailScenarioSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('accessPlatform');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'scenarios' => ['required', 'array'],
            'scenarios.*' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function scenarioSettings(): array
    {
        $submitted = $this->validated('scenarios', []);

        return collect(EmailScenario::cases())
            ->mapWithKeys(fn (EmailScenario $scenario): array => [
                $scenario->value => filter_var(
                    $submitted[$scenario->value] ?? false,
                    FILTER_VALIDATE_BOOLEAN,
                ),
            ])
            ->all();
    }
}
