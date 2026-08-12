<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalSchedule', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $edition = $this->route('festivalEdition');
        $stage = $this->route('festivalStage');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique((new FestivalStage)->getTable(), 'name')
                    ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)
                    ->ignore($stage instanceof FestivalStage ? $stage->id : null),
            ],
            'description' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
