<?php

namespace App\Http\Requests;

use App\Enums\StudioPermission;
use App\Models\Location;
use App\Models\TrainerType;
use App\Models\User;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;

class UpdateTrainerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageTrainers', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $userId = $this->route('trainer')?->user_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'trainer_type_id' => [
                'required',
                'integer',
                Rule::exists((new TrainerType)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'phone' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', File::image()->max('4mb')],
            'is_active' => ['nullable', 'boolean'],
            'create_login' => ['nullable', 'boolean'],
            'user_email' => [
                Rule::requiredIf($this->boolean('create_login')),
                'nullable',
                'email',
                'max:255',
                Rule::unique((new User)->getTable(), 'email')->ignore($userId),
            ],
            'user_password' => ['nullable', Password::defaults()],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(array_map(fn (StudioPermission $permission): string => $permission->value, StudioPermission::cases()))],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => [Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $countryCode = $this->route('account')?->country_code ?? 'UA';

        $this->merge([
            'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $countryCode),
            'email' => blank($this->input('email')) ? null : mb_strtolower(trim((string) $this->input('email'))),
            'user_email' => blank($this->input('user_email')) ? null : mb_strtolower(trim((string) $this->input('user_email'))),
            'location_ids' => collect($this->input('location_ids', []))
                ->filter(fn (mixed $locationId): bool => filled($locationId))
                ->map(fn (mixed $locationId): int => (int) $locationId)
                ->values()
                ->all(),
        ]);
    }
}
