<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalEntryStepReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalRegistrations', $account);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'request_changes', 'reject_entry'])],
            'comment' => ['nullable', 'required_if:decision,request_changes,reject_entry', 'string', 'max:5000'],
            'correction_due_at' => ['nullable', 'required_if:decision,request_changes', 'date', 'after:now'],
            'requirement_notes' => ['sometimes', 'array'],
            'requirement_notes.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
