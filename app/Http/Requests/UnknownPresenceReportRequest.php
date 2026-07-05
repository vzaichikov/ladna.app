<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnknownPresenceReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewReports', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $accountId = $account instanceof Account ? $account->id : 0;

        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
            'room_id' => [
                'nullable',
                'integer',
                Rule::exists((new Room)->getTable(), 'id')->where(fn ($query) => $query->where('account_id', $accountId)),
            ],
        ];
    }

    /**
     * @return array{date: string|null, location_id: int|null, room_id: int|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'date' => filled($validated['date'] ?? null) ? (string) $validated['date'] : null,
            'location_id' => filled($validated['location_id'] ?? null) ? (int) $validated['location_id'] : null,
            'room_id' => filled($validated['room_id'] ?? null) ? (int) $validated['room_id'] : null,
        ];
    }
}
