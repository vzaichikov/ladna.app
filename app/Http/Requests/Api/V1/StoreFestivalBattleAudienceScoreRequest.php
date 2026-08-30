<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Account;
use App\Models\AccountApiToken;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFestivalBattleAudienceScoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->attributes->get('account') instanceof Account
            && $this->attributes->get('accountApiToken') instanceof AccountApiToken;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['prohibited'],
            'audience_score_a' => ['required', 'integer', 'between:0,1000000'],
            'audience_score_b' => ['required', 'integer', 'between:0,1000000'],
            'measurement' => ['sometimes', 'array:metric,baseline_duration_ms,duration_ms,baseline_dbfs,mean_dbfs_a,mean_dbfs_b,peak_dbfs_a,peak_dbfs_b'],
            'measurement.metric' => ['required_with:measurement', 'string', 'in:baseline_adjusted_integrated_energy'],
            'measurement.baseline_duration_ms' => ['required_with:measurement', 'integer', 'in:2000'],
            'measurement.duration_ms' => ['required_with:measurement', 'integer', 'in:5000'],
            'measurement.baseline_dbfs' => ['sometimes', 'numeric', 'between:-160,0'],
            'measurement.mean_dbfs_a' => ['sometimes', 'numeric', 'between:-160,0'],
            'measurement.mean_dbfs_b' => ['sometimes', 'numeric', 'between:-160,0'],
            'measurement.peak_dbfs_a' => ['sometimes', 'numeric', 'between:-160,0'],
            'measurement.peak_dbfs_b' => ['sometimes', 'numeric', 'between:-160,0'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $scoreA = $this->integer('audience_score_a');
            $scoreB = $this->integer('audience_score_b');

            if (! $validator->errors()->has('audience_score_a')
                && ! $validator->errors()->has('audience_score_b')
                && $scoreA + $scoreB !== 1_000_000) {
                $validator->errors()->add('audience_score_b', __('app.festival_battle_api_scores_sum'));
            }
        }];
    }
}
