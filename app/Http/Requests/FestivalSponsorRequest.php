<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FestivalSponsorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');

        return $account instanceof Account
            && $edition instanceof FestivalEdition
            && $edition->account_id === $account->id
            && ($this->user()?->can('manageFestivals', $account) ?? false);
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
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'logo' => [$this->isMethod('post') ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:4096'],
            'short_description' => ['required', 'string', 'max:500'],
            'giveaway_description' => ['nullable', 'string', 'max:10000'],
            'show_on_all_pages' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{name: string, url: ?string, short_description: string, giveaway_description: ?string, show_on_all_pages: bool} */
    public function sponsorPayload(): array
    {
        return [
            'name' => $this->validated('name'),
            'url' => $this->validated('url'),
            'short_description' => $this->validated('short_description'),
            'giveaway_description' => $this->validated('giveaway_description'),
            'show_on_all_pages' => (bool) $this->validated('show_on_all_pages', false),
        ];
    }
}
