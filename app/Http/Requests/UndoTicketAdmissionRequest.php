<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Event;
use App\Models\FestivalEdition;
use App\Models\User;
use App\Support\EventFestivalStaffAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UndoTicketAdmissionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim($this->string('reason')->toString())]);
    }
}
