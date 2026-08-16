<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Event;
use App\Models\FestivalEdition;
use App\Models\User;
use App\Support\EventFestivalStaffAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDoorTicketSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(EventFestivalStaffAccess $staffAccess): bool
    {
        $account = $this->route('account');

        if (! $account instanceof Account) {
            return false;
        }

        if ((bool) $this->user()?->can('doorStaff', $account)) {
            return true;
        }

        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $event = $this->route('event');
        if ($event instanceof Event) {
            return $staffAccess->canAccessEvent($user, $account, $event);
        }

        $edition = $this->route('festivalEdition');

        return $edition instanceof FestivalEdition
            && $staffAccess->canAccessFestival($user, $account, $edition);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ticket_type_id' => ['required', 'integer', 'min:1'],
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_email' => ['nullable', 'email:rfc', 'max:255'],
            'provider' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['required', 'uuid'],
            'terms_accepted' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function saleInput(): array
    {
        return $this->validated();
    }

    protected function prepareForValidation(): void
    {
        $email = trim($this->string('guest_email', $this->string('email')->toString())->toString());

        $this->merge([
            'guest_name' => preg_replace('/\s+/u', ' ', trim($this->string('guest_name')->toString())),
            'guest_email' => $email === '' ? null : mb_strtolower($email),
            'provider' => trim($this->string('provider')->toString()) ?: null,
        ]);
    }
}
