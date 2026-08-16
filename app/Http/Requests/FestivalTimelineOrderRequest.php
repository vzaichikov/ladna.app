<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\User;
use App\Support\EventFestivalStaffAccess;
use Illuminate\Foundation\Http\FormRequest;

class FestivalTimelineOrderRequest extends FormRequest
{
    public function authorize(EventFestivalStaffAccess $staffAccess): bool
    {
        $account = $this->route('account');

        if (! $account instanceof Account) {
            return false;
        }

        if ((bool) $this->user()?->can('manageFestivalSchedule', $account)) {
            return true;
        }

        $user = $this->user();
        $edition = $this->route('festivalEdition');

        return $user instanceof User
            && $edition instanceof FestivalEdition
            && $staffAccess->canAccessFestival($user, $account, $edition);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
