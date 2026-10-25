<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FestivalEntryStepReviewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $deadline = $this->input('correction_due_at');
        $edition = $this->route('festivalEdition');
        if ($edition instanceof FestivalEdition && is_string($deadline)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $deadline)) {
            try {
                $format = strlen($deadline) === 19 ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
                $localDeadline = CarbonImmutable::createFromFormat('!'.$format, $deadline, $edition->timezone);
                if ($localDeadline->format($format) !== $deadline) {
                    throw ValidationException::withMessages(['correction_due_at' => __('validation.date')]);
                }
                $this->merge(['correction_due_at' => $localDeadline->utc()->toDateTimeString()]);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['correction_due_at' => __('validation.date')]);
            }
        }
    }

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
