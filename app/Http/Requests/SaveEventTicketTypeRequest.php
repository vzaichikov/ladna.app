<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicketType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveEventTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageEvents', $account);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'inventory' => ['required', 'integer', 'min:1', 'max:1000000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'early_bird_price' => ['nullable', 'required_with:early_bird_ends_at,early_bird_quota', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/', 'lt:price'],
            'early_bird_ends_at' => ['nullable', 'required_with:early_bird_price', 'date_format:Y-m-d\TH:i'],
            'early_bird_quota' => ['nullable', 'integer', 'min:1', 'max:1000000', 'lte:inventory'],
            'sales_starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'sales_ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:sales_starts_at'],
            'max_per_order' => ['required', 'integer', 'min:1', 'max:100', 'lte:inventory'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('app.name'),
            'description' => __('app.description'),
            'inventory' => __('app.event_inventory'),
            'price' => __('app.price'),
            'early_bird_price' => __('app.event_early_price'),
            'early_bird_ends_at' => __('app.event_early_ends'),
            'early_bird_quota' => __('app.event_early_quota'),
            'sales_starts_at' => __('app.event_sales_starts'),
            'sales_ends_at' => __('app.event_sales_ends'),
            'max_per_order' => __('app.event_max_per_order'),
            'is_active' => __('app.active'),
            'sort_order' => __('app.sort_order'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ticketType = $this->route('eventTicketType');

                if (! $ticketType instanceof EventTicketType) {
                    return;
                }

                if ((int) $this->input('inventory') < $ticketType->soldOrHeldQuantity()) {
                    $validator->errors()->add('inventory', __('app.event_inventory_below_reserved'));
                }

                if (filled($this->input('early_bird_quota'))
                    && (int) $this->input('early_bird_quota') < $ticketType->earlyBirdSoldOrHeldQuantity()) {
                    $validator->errors()->add('early_bird_quota', __('app.event_early_quota_below_sold'));
                }

                $event = $this->route('event');

                if ($event instanceof Event
                    && $event->isPublished()
                    && ! $this->boolean('is_active')
                    && $event->ticketTypes()->where('is_active', true)->whereKeyNot($ticketType->id)->doesntExist()) {
                    $validator->errors()->add('is_active', __('app.event_ticket_type_required'));
                }
            },
        ];
    }
}
