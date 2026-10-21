<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSmsAlertSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['telegram_alerts_enabled' => ['required', 'boolean']];
    }
}
