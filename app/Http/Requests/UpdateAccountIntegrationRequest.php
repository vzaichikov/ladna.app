<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\IntegrationSetting;

class UpdateAccountIntegrationRequest extends UpdateIntegrationRequest
{
    protected function authorizedToManageIntegration(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    protected function existingSetting(): ?IntegrationSetting
    {
        $account = $this->route('account');

        if (! $account instanceof Account) {
            return null;
        }

        return IntegrationSetting::forAccount($account)
            ->where('provider', (string) $this->route('provider'))
            ->first();
    }
}
