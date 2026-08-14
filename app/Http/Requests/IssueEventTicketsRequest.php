<?php

namespace App\Http\Requests;

use App\Actions\IssueManualEventTickets;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicketType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueEventTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');
        $event = $this->route('event');

        return $account instanceof Account
            && $event instanceof Event
            && $event->account_id === $account->id
            && (bool) $this->user()?->can('manageEvents', $account);
    }

    public function rules(): array
    {
        $account = $this->route('account');
        $event = $this->route('event');

        return [
            'ticket_type_id' => [
                'required',
                'integer',
                Rule::exists((new EventTicketType)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('event_id', $event?->id)
                    ->where('is_active', true),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_email' => ['nullable', 'email:rfc', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'payment_kind' => ['required', Rule::in(['paid', 'complimentary'])],
            'payment_method' => [
                'nullable',
                Rule::requiredIf($this->input('payment_kind') === 'paid'),
                Rule::in(IssueManualEventTickets::PAYMENT_METHODS),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ticket_type_id' => __('app.event_ticket_option'),
            'quantity' => __('app.event_ticket_quantity'),
            'buyer_name' => __('app.person_name'),
            'buyer_email' => __('app.email'),
            'buyer_phone' => __('app.phone'),
            'payment_kind' => __('app.event_manual_payment_kind'),
            'payment_method' => __('app.payment_method'),
        ];
    }
}
