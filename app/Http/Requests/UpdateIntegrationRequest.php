<?php

namespace App\Http\Requests;

use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class UpdateIntegrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->authorizedToManageIntegration();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return IntegrationCatalog::rulesFor($this->provider());
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('is_enabled')) {
                    return;
                }

                $submittedCredentials = $this->input('credentials', []);

                if (! is_array($submittedCredentials)) {
                    return;
                }

                $missingFields = IntegrationCatalog::missingRequiredFields(
                    $this->provider(),
                    $submittedCredentials,
                    $this->existingCredentials(),
                );

                foreach ($missingFields as $field => $labelKey) {
                    $validator->errors()->add('credentials.'.$field, __('app.integration_field_required', [
                        'field' => __($labelKey),
                    ]));
                }
            },
        ];
    }

    /**
     * @return array{is_enabled: bool, credentials: array<string, mixed>}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'is_enabled' => $this->boolean('is_enabled'),
            'credentials' => IntegrationCatalog::credentialsForStorage(
                $this->provider(),
                $validated['credentials'] ?? [],
                $this->existingCredentials(),
            ),
        ];
    }

    abstract protected function authorizedToManageIntegration(): bool;

    abstract protected function existingSetting(): ?IntegrationSetting;

    private function provider(): string
    {
        return (string) $this->route('provider');
    }

    /**
     * @return array<string, mixed>
     */
    private function existingCredentials(): array
    {
        return $this->existingSetting()?->credentials ?? [];
    }
}
