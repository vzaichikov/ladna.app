<?php

namespace App\Http\Requests;

use App\Models\IntegrationSetting;

class UpdatePlatformIntegrationRequest extends UpdateIntegrationRequest
{
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
