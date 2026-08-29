<?php

namespace App\Http\Requests;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Models\Account;
use App\Models\FestivalEdition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueFestivalAudienceTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');

        return $account instanceof Account
            && $edition instanceof FestivalEdition
            && $edition->account_id === $account->id
            && (bool) $this->user()?->can('manageFestivalFinance', $account);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');

        return [
            'audience' => ['required', Rule::in(['judges'])],
            'festival_admission_type_id' => [
                'required',
                'integer',
                Rule::exists('festival_admission_types', 'id')->where(fn ($query) => $query
                    ->where('account_id', $account instanceof Account ? $account->id : 0)
                    ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : 0)
                    ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
                    ->where('is_active', true)),
            ],
        ];
    }
}
