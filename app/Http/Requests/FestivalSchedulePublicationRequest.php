<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalSchedulePublicationRequest extends FormRequest
{
    protected $errorBag = 'programPublication';

    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalSchedule', $account);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['publish_all', 'unpublish_all', 'publish_selected', 'unpublish_selected'])],
            'item_ids' => ['exclude_unless:operation,publish_selected,unpublish_selected', 'required', 'array', 'list'],
            'item_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
