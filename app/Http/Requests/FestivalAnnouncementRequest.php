<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use Illuminate\Foundation\Http\FormRequest;

class FestivalAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');

        return $account instanceof Account
            && $edition instanceof FestivalEdition
            && $edition->account_id === $account->id
            && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }
}
