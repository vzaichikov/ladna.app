<?php

namespace App\Http\Controllers;

use App\Actions\SaveEvent;
use App\Enums\EmailScenario;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Http\Requests\SaveEventRequest;
use App\Models\Account;
use App\Models\Event;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\WorkingLocationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(
        Request $request,
        Account $account,
        WorkingLocationContext $workingLocationContext,
    ): View {
        abort_unless($request->user()?->can('manageEvents', $account) || $request->user()?->can('checkInEventTickets', $account), 403);
        $canManage = (bool) $request->user()?->can('manageEvents', $account);
        $selectedLocationId = $workingLocationContext->filterLocationId($account, includeInactive: true);
        $tab = in_array($request->query('tab'), ['upcoming', 'draft', 'past', 'cancelled'], true)
            ? (string) $request->query('tab')
            : 'upcoming';
        $events = $account->events()
            ->with(['location', 'rooms', 'ticketTypes'])
            ->withCount([
                'tickets',
                'tickets as checked_in_tickets_count' => fn ($query) => $query->where('is_checked_in', true),
            ])
            ->withSum(['orders as revenue_cents' => fn ($query) => $query->whereIn('status', [
                EventOrderStatus::Paid->value,
                EventOrderStatus::RefundRequired->value,
                EventOrderStatus::PaidRequiresRefund->value,
            ])], 'amount_cents')
            ->when($tab === 'upcoming', fn ($query) => $query->where('status', EventStatus::Published->value)->where('ends_at', '>=', now()))
            ->when($tab === 'draft', fn ($query) => $query->where('status', EventStatus::Draft->value))
            ->when($tab === 'past', fn ($query) => $query->where('status', EventStatus::Published->value)->where('ends_at', '<', now()))
            ->when($tab === 'cancelled', fn ($query) => $query->whereIn('status', [EventStatus::Cancelled->value, EventStatus::Archived->value]))
            ->when($selectedLocationId, fn ($query, int $locationId) => $query->where('location_id', $locationId))
            ->orderBy($tab === 'past' ? 'starts_at' : 'starts_at', $tab === 'past' ? 'desc' : 'asc')
            ->paginate(20)
            ->withQueryString();

        return view('events.index', [
            'account' => $account,
            'events' => $events,
            'tab' => $tab,
            'canManage' => $canManage,
            'locations' => $account->locations()->orderBy('name')->get(['id', 'name', 'is_active']),
            'selectedLocationId' => $selectedLocationId,
            'locationQuery' => $request->query->has('location_id')
                ? ['location_id' => $request->query('location_id')]
                : [],
        ]);
    }

    public function create(
        Request $request,
        Account $account,
        WorkingLocationContext $workingLocationContext,
    ): View {
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        $event = new Event([
            'location_id' => $workingLocationContext->formLocationId($account),
            'timezone' => $account->timezone ?? config('app.timezone'),
            'currency' => $account->default_currency,
            'starts_at' => now($account->timezone)->addWeek()->startOfHour(),
            'ends_at' => now($account->timezone)->addWeek()->startOfHour()->addHours(2),
        ]);

        return view('events.form', $this->formData($account, $event));
    }

    public function store(SaveEventRequest $request, Account $account, SaveEvent $saveEvent): RedirectResponse
    {
        $event = $saveEvent->execute($account, $request->validated());
        $this->storeMedia($account, $event, $request);

        return redirect()->route('dashboard.accounts.events.edit', [$account, $event])
            ->with('status', __('app.event_created'));
    }

    public function edit(Request $request, Account $account, Event $event): View
    {
        $this->ensureEventBelongsToAccount($account, $event);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        $event->load(['rooms', 'media', 'ticketTypes'])->loadCount('orders');

        return view('events.form', $this->formData($account, $event));
    }

    public function update(
        SaveEventRequest $request,
        Account $account,
        Event $event,
        SaveEvent $saveEvent,
        TransactionalMailDispatcher $mailDispatcher,
    ): RedirectResponse {
        $this->ensureEventBelongsToAccount($account, $event);
        $before = [
            $event->starts_at?->toIso8601String(),
            $event->ends_at?->toIso8601String(),
            $event->venue_kind->value,
            $event->location_id,
            $event->external_venue_name,
            $event->external_address,
            $event->rooms()->pluck('rooms.id')->sort()->values()->all(),
        ];
        $event = $saveEvent->execute($account, $request->validated(), $event);
        $this->storeMedia($account, $event, $request);
        $after = [
            $event->starts_at?->toIso8601String(),
            $event->ends_at?->toIso8601String(),
            $event->venue_kind->value,
            $event->location_id,
            $event->external_venue_name,
            $event->external_address,
            $event->rooms()->pluck('rooms.id')->sort()->values()->all(),
        ];

        if ($event->isPublished() && $before !== $after) {
            $event->orders()->whereIn('status', [
                EventOrderStatus::Paid->value,
                EventOrderStatus::RefundRequired->value,
            ])->each(fn ($order) => $mailDispatcher->eventBuyerNotice($order, EmailScenario::EventUpdated));
        }

        return back()->with('status', __('app.event_updated'));
    }

    public function publish(Request $request, Account $account, Event $event, SaveEvent $saveEvent): RedirectResponse
    {
        $this->ensureEventBelongsToAccount($account, $event);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        $saveEvent->publish($account, $event);

        return back()->with('status', __('app.event_published'));
    }

    public function cancel(
        Request $request,
        Account $account,
        Event $event,
        TransactionalMailDispatcher $mailDispatcher,
    ): RedirectResponse {
        $this->ensureEventBelongsToAccount($account, $event);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        abort_unless($event->status === EventStatus::Published, 422);

        DB::transaction(function () use ($event): void {
            $event->forceFill(['status' => EventStatus::Cancelled, 'cancelled_at' => now()])->save();
            $event->orders()
                ->where('status', EventOrderStatus::Paid->value)
                ->where('amount_cents', '>', 0)
                ->update(['status' => EventOrderStatus::RefundRequired->value]);
            $event->tickets()->update(['status' => 'voided', 'is_checked_in' => false, 'checked_in_at' => null]);
        });
        $event->orders()->whereIn('status', [
            EventOrderStatus::Paid->value,
            EventOrderStatus::RefundRequired->value,
            EventOrderStatus::PaidRequiresRefund->value,
        ])->each(fn ($order) => $mailDispatcher->eventBuyerNotice($order, EmailScenario::EventCancelled));

        return back()->with('status', __('app.event_cancelled'));
    }

    public function archive(Request $request, Account $account, Event $event): RedirectResponse
    {
        $this->ensureEventBelongsToAccount($account, $event);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        abort_unless(
            $event->status !== EventStatus::Archived
            && ($event->status === EventStatus::Cancelled || $event->isCompleted()),
            422,
        );
        $event->forceFill(['status' => EventStatus::Archived, 'archived_at' => now()])->save();

        return back()->with('status', __('app.event_archived'));
    }

    public function destroy(Request $request, Account $account, Event $event): RedirectResponse
    {
        $this->ensureEventBelongsToAccount($account, $event);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
        abort_if($event->status !== EventStatus::Draft || $event->orders()->exists(), 422);
        $paths = $event->media()->whereNotNull('image_path')->pluck('image_path')->all();
        $event->delete();
        Storage::disk('public')->delete($paths);

        return redirect()->route('dashboard.accounts.events.index', $account)
            ->with('status', __('app.event_deleted'));
    }

    private function ensureEventBelongsToAccount(Account $account, Event $event): void
    {
        abort_unless($event->account_id === $account->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Account $account, Event $event): array
    {
        return [
            'account' => $account,
            'event' => $event,
            'locations' => $account->locations()
                ->where(fn ($query) => $query->active()->when(
                    $event->location_id,
                    fn ($query, int $locationId) => $query->orWhere('locations.id', $locationId),
                ))
                ->with(['rooms' => fn ($query) => $query->active()->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ];
    }

    private function storeMedia(Account $account, Event $event, SaveEventRequest $request): void
    {
        if ($request->hasFile('cover_image')) {
            $event->media()->where('is_cover', true)->update(['is_cover' => false]);
            $path = $request->file('cover_image')->store("event-media/{$account->id}/{$event->id}", 'public');
            $event->media()->create([
                'account_id' => $account->id,
                'kind' => 'image',
                'image_path' => $path,
                'alt_text' => $event->title,
                'sort_order' => 0,
                'is_cover' => true,
            ]);
        }

        foreach ($request->file('gallery_images', []) as $index => $image) {
            $event->media()->create([
                'account_id' => $account->id,
                'kind' => 'image',
                'image_path' => $image->store("event-media/{$account->id}/{$event->id}", 'public'),
                'alt_text' => $event->title,
                'sort_order' => 10 + $index,
            ]);
        }

        foreach (array_filter($request->validated('video_urls', [])) as $index => $videoUrl) {
            $event->media()->firstOrCreate(
                ['external_url' => $videoUrl],
                ['account_id' => $account->id, 'kind' => 'video', 'sort_order' => 100 + $index],
            );
        }
    }
}
