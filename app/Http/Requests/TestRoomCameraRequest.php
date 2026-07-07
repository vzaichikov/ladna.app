<?php

namespace App\Http\Requests;

use App\Rules\RtspUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TestRoomCameraRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account?->allowsRtspCameras()
            && ($this->user()?->isPlatformAdmin() ?? false)
            && ($this->user()?->can('manageStudioSettings', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rtsp_url' => ['required', 'string', 'max:2048', new RtspUrl],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rtsp_url' => blank($this->input('rtsp_url')) ? null : trim((string) $this->input('rtsp_url')),
        ]);
    }
}
