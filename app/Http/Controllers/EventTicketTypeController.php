<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveEventTicketTypeRequest;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicketType;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class EventTicketTypeController extends Controller
{
    public function store(SaveEventTicketTypeRequest $request, Account $account, Event $event): RedirectResponse
    {
        $this->ensureScope($account, $event);
        $event->ticketTypes()->create([
            'account_id' => $account->id,
            ...$this->attributes($request, $event),
        ]);

        return back()->with('status', __('app.event_ticket_type_created'));
    }

    public function update(SaveEventTicketTypeRequest $request, Account $account, Event $event, EventTicketType $eventTicketType): RedirectResponse
    {
        $this->ensureScope($account, $event, $eventTicketType);
        $eventTicketType->update($this->attributes($request, $event));

        return back()->with('status', __('app.event_ticket_type_updated'));
    }

    public function destroy(Account $account, Event $event, EventTicketType $eventTicketType): RedirectResponse
    {
        $this->ensureScope($account, $event, $eventTicketType);
        abort_if($eventTicketType->orderItems()->exists(), 422);
        abort_if(
            $event->isPublished()
            && $eventTicketType->is_active
            && $event->ticketTypes()->where('is_active', true)->whereKeyNot($eventTicketType->id)->doesntExist(),
            422,
        );
        $eventTicketType->delete();

        return back()->with('status', __('app.event_ticket_type_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SaveEventTicketTypeRequest $request, Event $event): array
    {
        $input = $request->validated();

        return [
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'inventory' => $input['inventory'],
            'price_cents' => $this->moneyToCents($input['price']),
            'early_bird_price_cents' => filled($input['early_bird_price'] ?? null) ? $this->moneyToCents($input['early_bird_price']) : null,
            'early_bird_ends_at' => $this->toUtc($input['early_bird_ends_at'] ?? null, $event->timezone),
            'early_bird_quota' => $input['early_bird_quota'] ?? null,
            'sales_starts_at' => $this->toUtc($input['sales_starts_at'] ?? null, $event->timezone),
            'sales_ends_at' => $this->toUtc($input['sales_ends_at'] ?? null, $event->timezone),
            'max_per_order' => $input['max_per_order'],
            'is_active' => $request->boolean('is_active'),
            'sort_order' => $input['sort_order'],
        ];
    }

    private function moneyToCents(mixed $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim((string) $value), 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function toUtc(?string $value, string $timezone): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone)->utc() : null;
    }

    private function ensureScope(Account $account, Event $event, ?EventTicketType $ticketType = null): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_if($ticketType && ($ticketType->account_id !== $account->id || $ticketType->event_id !== $event->id), 404);
    }
}
