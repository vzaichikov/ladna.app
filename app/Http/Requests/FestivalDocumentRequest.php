<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['rules', 'schedule', 'guide', 'document'])],
            'visibility' => ['required', Rule::in(['public', 'portal', 'staff'])],
            'file' => [$this->isMethod('post') ? 'required' : 'nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:51200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
