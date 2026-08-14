<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveEventTicketTypeRequest;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicketType;
use App\Support\Payments\PaymentAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventTicketTypeController extends Controller
{
    public function index(Request $request, Account $account, Event $event): View
    {
        $this->authorizeManagement($request, $account, $event);
        $search = $request->string('q')->trim()->toString();
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? (string) $request->query('status')
            : null;
        $ticketTypes = $event->ticketTypes()
            ->withSoldOrHeldQuantity()
            ->withCount('orderItems')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->paginate(20)
            ->withQueryString();

        return view('events.ticket-types.index', [
            'account' => $account,
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'activeTicketTypeCount' => $event->ticketTypes()->where('is_active', true)->count(),
            'filters' => ['q' => $search, 'status' => $status],
            'hasFilters' => $search !== '' || $status !== null,
        ]);
    }

    public function create(Request $request, Account $account, Event $event): View
    {
        $this->authorizeManagement($request, $account, $event);
        $ticketType = new EventTicketType([
            'inventory' => 20,
            'price_cents' => 0,
            'max_per_order' => 10,
            'is_active' => true,
            'sort_order' => ((int) $event->ticketTypes()->max('sort_order')) + 10,
        ]);

        return view('events.ticket-types.form', compact('account', 'event', 'ticketType'));
    }

    public function edit(Request $request, Account $account, Event $event, EventTicketType $eventTicketType): View
    {
        $this->authorizeManagement($request, $account, $event, $eventTicketType);

        return view('events.ticket-types.form', [
            'account' => $account,
            'event' => $event,
            'ticketType' => $eventTicketType,
        ]);
    }

    public function store(SaveEventTicketTypeRequest $request, Account $account, Event $event): RedirectResponse
    {
        $this->ensureScope($account, $event);
        $event->ticketTypes()->create([
            'account_id' => $account->id,
            ...$this->attributes($request, $event),
        ]);

        return redirect()->route('dashboard.accounts.events.ticket-types.index', [$account, $event])
            ->with('status', __('app.event_ticket_type_created'));
    }

    public function update(SaveEventTicketTypeRequest $request, Account $account, Event $event, EventTicketType $eventTicketType): RedirectResponse
    {
        $this->ensureScope($account, $event, $eventTicketType);
        $eventTicketType->update($this->attributes($request, $event));

        return redirect()->route('dashboard.accounts.events.ticket-types.index', [$account, $event])
            ->with('status', __('app.event_ticket_type_updated'));
    }

    public function destroy(Request $request, Account $account, Event $event, EventTicketType $eventTicketType): RedirectResponse
    {
        $this->authorizeManagement($request, $account, $event, $eventTicketType);
        abort_if($eventTicketType->orderItems()->exists(), 422);
        abort_if(
            $event->isPublished()
            && $eventTicketType->is_active
            && $event->ticketTypes()->where('is_active', true)->whereKeyNot($eventTicketType->id)->doesntExist(),
            422,
        );
        $eventTicketType->delete();

        return redirect()->route('dashboard.accounts.events.ticket-types.index', [$account, $event])
            ->with('status', __('app.event_ticket_type_deleted'));
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
            'price_cents' => (int) PaymentAmounts::decimalToCents($input['price']),
            'early_bird_price_cents' => filled($input['early_bird_price'] ?? null)
                ? PaymentAmounts::decimalToCents($input['early_bird_price'])
                : null,
            'early_bird_ends_at' => $this->toUtc($input['early_bird_ends_at'] ?? null, $event->timezone),
            'early_bird_quota' => $input['early_bird_quota'] ?? null,
            'sales_starts_at' => $this->toUtc($input['sales_starts_at'] ?? null, $event->timezone),
            'sales_ends_at' => $this->toUtc($input['sales_ends_at'] ?? null, $event->timezone),
            'max_per_order' => $input['max_per_order'],
            'is_active' => $request->boolean('is_active'),
            'sort_order' => $input['sort_order'],
        ];
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

    private function authorizeManagement(
        Request $request,
        Account $account,
        Event $event,
        ?EventTicketType $ticketType = null,
    ): void {
        $this->ensureScope($account, $event, $ticketType);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
    }
}
