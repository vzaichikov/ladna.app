<?php

namespace App\Http\Requests;

use App\Support\Telegram\CustomerTelegramLinkResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountCustomerTelegramBotPlacementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageStudioSettings', $this->route('account')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            CustomerTelegramLinkResolver::PlacementCustomerDashboard => ['required', 'boolean'],
            CustomerTelegramLinkResolver::PlacementPublicStudio => ['required', 'boolean'],
            CustomerTelegramLinkResolver::PlacementPublicContacts => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{customer_dashboard: bool, public_studio: bool, public_contacts: bool}
     */
    public function payload(): array
    {
        return [
            CustomerTelegramLinkResolver::PlacementCustomerDashboard => $this->boolean(CustomerTelegramLinkResolver::PlacementCustomerDashboard),
            CustomerTelegramLinkResolver::PlacementPublicStudio => $this->boolean(CustomerTelegramLinkResolver::PlacementPublicStudio),
            CustomerTelegramLinkResolver::PlacementPublicContacts => $this->boolean(CustomerTelegramLinkResolver::PlacementPublicContacts),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            CustomerTelegramLinkResolver::PlacementCustomerDashboard => $this->boolean(CustomerTelegramLinkResolver::PlacementCustomerDashboard),
            CustomerTelegramLinkResolver::PlacementPublicStudio => $this->boolean(CustomerTelegramLinkResolver::PlacementPublicStudio),
            CustomerTelegramLinkResolver::PlacementPublicContacts => $this->boolean(CustomerTelegramLinkResolver::PlacementPublicContacts),
        ]);
    }
}
