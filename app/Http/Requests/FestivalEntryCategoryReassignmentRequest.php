<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalEntryCategoryReassignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalRegistrations', $account);
    }

    public function rules(): array
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');

        return [
            'festival_category_id' => [
                'required',
                'integer',
                Rule::exists((new FestivalCategory)->getTable(), 'id')
                    ->where('account_id', $account instanceof Account ? $account->id : null)
                    ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)
                    ->where('is_active', true),
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
