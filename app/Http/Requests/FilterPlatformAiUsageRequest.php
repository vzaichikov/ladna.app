<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use App\Models\AiProviderRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class FilterPlatformAiUsageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['today', '7', '30', 'custom'])],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'channel' => ['nullable', 'string', 'max:60'],
            'provider' => ['nullable', Rule::in(array_column(AiProvider::cases(), 'value'))],
            'model' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                AiProviderRequest::StatusSucceeded,
                AiProviderRequest::StatusFailed,
            ])],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('from') || ! $this->filled('to')) {
                    return;
                }

                $from = Carbon::createFromFormat('Y-m-d', (string) $this->input('from'));
                $to = Carbon::createFromFormat('Y-m-d', (string) $this->input('to'));

                if ($from->gt($to)) {
                    $validator->errors()->add('to', __('app.ai_usage_date_order_validation'));
                }

                if ((int) $from->diffInDays($to) > 365) {
                    $validator->errors()->add('to', __('app.ai_usage_date_range_validation'));
                }
            },
        ];
    }
}
