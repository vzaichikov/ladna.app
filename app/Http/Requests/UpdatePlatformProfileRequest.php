<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;

class UpdatePlatformProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')->ignore($this->user())],
            'phone' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max('2mb')],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }
}
