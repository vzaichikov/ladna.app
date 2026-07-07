<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Rules\RtspUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRoomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account?->allowsRtspCameras()
            && ($this->user()?->can('manageStudioSettings', $account) ?? false);
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
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($this->canManageCameraSettings()) {
            $rules['rtsp_url'] = [Rule::requiredIf($this->boolean('rtsp_enabled')), 'nullable', 'string', 'max:2048', new RtspUrl];
            $rules['rtsp_enabled'] = ['nullable', 'boolean'];
        } else {
            $rules['rtsp_url'] = ['prohibited'];
            $rules['rtsp_enabled'] = ['prohibited'];
        }

        return $rules;
    }

    private function canManageCameraSettings(): bool
    {
        return $this->user()?->isPlatformAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rtsp_url')) {
            $this->merge([
                'rtsp_url' => blank($this->input('rtsp_url')) ? null : trim((string) $this->input('rtsp_url')),
            ]);
        }
    }
}
