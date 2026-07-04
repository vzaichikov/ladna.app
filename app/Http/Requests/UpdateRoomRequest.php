<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Rules\RtspUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageStudioSettings', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $rules = [
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($account?->allowsRtspCameras()) {
            $rules['rtsp_url'] = [Rule::requiredIf($this->boolean('rtsp_enabled')), 'nullable', 'string', 'max:2048', new RtspUrl];
            $rules['rtsp_enabled'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('rtsp_url')) {
            return;
        }

        $this->merge([
            'rtsp_url' => blank($this->input('rtsp_url')) ? null : trim((string) $this->input('rtsp_url')),
        ]);
    }
}
