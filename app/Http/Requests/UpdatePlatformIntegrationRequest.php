<?php

namespace App\Http\Requests;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;

class UpdatePlatformIntegrationRequest extends UpdateIntegrationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        if ($this->route('provider') === IntegrationProvider::Monopay->value) {
            $rules['event_iframe_v2_enabled'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function eventIframeV2Enabled(): bool
    {
        return $this->route('provider') === IntegrationProvider::Monopay->value
            && $this->boolean('event_iframe_v2_enabled');
    }

    protected function authorizedToManageIntegration(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    protected function existingSetting(): ?IntegrationSetting
    {
        return IntegrationSetting::platform()
            ->where('provider', (string) $this->route('provider'))
            ->first();
    }
}
