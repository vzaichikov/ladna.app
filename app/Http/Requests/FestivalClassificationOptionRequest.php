<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalClassificationOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalClassificationOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        $axis = $this->route('festivalClassificationAxis');
        $option = $this->route('festivalClassificationOption');

        return [
            'code' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('festival_classification_options')->where('festival_classification_axis_id', $axis?->id)->ignore($option instanceof FestivalClassificationOption ? $option->id : null)],
            'label' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
