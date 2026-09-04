<?php

namespace App\Http\Controllers;

use App\Enums\PromoCodeDiscountType;
use App\Http\Requests\SaveEventPromoCodeRequest;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventPromoCode;
use App\Support\Payments\PaymentAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EventPromoCodeController extends Controller
{
    public function index(Request $request, Account $account, Event $event): View
    {
        $this->authorizeManagement($request, $account, $event);
        $search = $request->string('q')->trim()->toString();
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? (string) $request->query('status')
            : null;
        $promoCodes = $event->promoCodes()
            ->withCount([
                'ticketTypes',
                'orders as uses_count' => fn (Builder $query): Builder => $query->reservingPromotionUse(),
                'orders as history_count',
            ])
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($status === 'active', fn (Builder $query): Builder => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query): Builder => $query->where('is_active', false))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('events.promo-codes.index', [
            'account' => $account,
            'event' => $event,
            'promoCodes' => $promoCodes,
            'filters' => ['q' => $search, 'status' => $status],
            'hasFilters' => $search !== '' || $status !== null,
        ]);
    }

    public function create(Request $request, Account $account, Event $event): View
    {
        $this->authorizeManagement($request, $account, $event);
        $localNow = now($event->timezone)->startOfHour();
        $localEnd = $event->ends_at?->copy()->timezone($event->timezone);

        return view('events.promo-codes.form', [
            'account' => $account,
            'event' => $event,
            'promoCode' => new EventPromoCode([
                'currency' => $event->currency,
                'starts_at' => $localNow->copy()->utc(),
                'ends_at' => ($localEnd?->isAfter($localNow) ? $localEnd : $localNow->copy()->addMonth())->utc(),
                'max_uses_per_identity' => 1,
                'is_active' => true,
            ]),
            'ticketTypes' => $event->ticketTypes()->orderBy('sort_order')->orderBy('id')->get(),
            'selectedTicketTypeIds' => collect(),
        ]);
    }

    public function store(SaveEventPromoCodeRequest $request, Account $account, Event $event): RedirectResponse
    {
        $this->ensureScope($account, $event);
        $promoCode = DB::transaction(function () use ($request, $account, $event): EventPromoCode {
            $promoCode = $event->promoCodes()->create([
                'account_id' => $account->id,
                ...$this->attributes($request, $event),
            ]);
            $promoCode->ticketTypes()->syncWithPivotValues(
                $request->validated('ticket_type_ids'),
                ['account_id' => $account->id, 'event_id' => $event->id],
            );

            return $promoCode;
        }, 3);

        return redirect()->route('dashboard.accounts.events.promo-codes.index', [$account, $event])
            ->with('status', __('app.promo_code_created', ['name' => $promoCode->name]));
    }

    public function edit(Request $request, Account $account, Event $event, EventPromoCode $eventPromoCode): View
    {
        $this->authorizeManagement($request, $account, $event, $eventPromoCode);
        $eventPromoCode->loadMissing('ticketTypes:id');

        return view('events.promo-codes.form', [
            'account' => $account,
            'event' => $event,
            'promoCode' => $eventPromoCode,
            'ticketTypes' => $event->ticketTypes()->orderBy('sort_order')->orderBy('id')->get(),
            'selectedTicketTypeIds' => $eventPromoCode->ticketTypes->pluck('id'),
        ]);
    }

    public function update(
        SaveEventPromoCodeRequest $request,
        Account $account,
        Event $event,
        EventPromoCode $eventPromoCode,
    ): RedirectResponse {
        $this->ensureScope($account, $event, $eventPromoCode);

        DB::transaction(function () use ($request, $account, $event, $eventPromoCode): void {
            $promoCode = EventPromoCode::query()->whereKey($eventPromoCode)->lockForUpdate()->firstOrFail();
            $promoCode->update($this->attributes($request, $event));
            $promoCode->ticketTypes()->syncWithPivotValues(
                $request->validated('ticket_type_ids'),
                ['account_id' => $account->id, 'event_id' => $event->id],
            );
        }, 3);

        return redirect()->route('dashboard.accounts.events.promo-codes.index', [$account, $event])
            ->with('status', __('app.promo_code_updated', ['name' => $eventPromoCode->name]));
    }

    public function destroy(
        Request $request,
        Account $account,
        Event $event,
        EventPromoCode $eventPromoCode,
    ): RedirectResponse {
        $this->authorizeManagement($request, $account, $event, $eventPromoCode);

        DB::transaction(function () use ($eventPromoCode): void {
            $promoCode = EventPromoCode::query()->whereKey($eventPromoCode)->lockForUpdate()->firstOrFail();

            if ($promoCode->orders()->exists()) {
                throw ValidationException::withMessages(['promo_code' => __('app.promo_code_delete_history_block')]);
            }

            $promoCode->delete();
        }, 3);

        return redirect()->route('dashboard.accounts.events.promo-codes.index', [$account, $event])
            ->with('status', __('app.promo_code_deleted'));
    }

    /** @return array<string, mixed> */
    private function attributes(SaveEventPromoCodeRequest $request, Event $event): array
    {
        $input = $request->validated();
        $discountType = PromoCodeDiscountType::from($input['discount_type']);

        return [
            'name' => $input['name'],
            'code' => $input['code'],
            'discount_type' => $discountType,
            'discount_value' => $discountType === PromoCodeDiscountType::Fixed
                ? PaymentAmounts::decimalToCents($input['discount_amount'])
                : (int) $input['discount_amount'],
            'currency' => strtoupper($event->currency),
            'starts_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $input['starts_at'], $event->timezone)->utc(),
            'ends_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $input['ends_at'], $event->timezone)->utc(),
            'max_total_uses' => $input['max_total_uses'] ?? null,
            'max_uses_per_identity' => $input['max_uses_per_identity'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function ensureScope(Account $account, Event $event, ?EventPromoCode $promoCode = null): void
    {
        abort_unless($event->account_id === $account->id, 404);
        abort_if($promoCode && ($promoCode->account_id !== $account->id || $promoCode->event_id !== $event->id), 404);
    }

    private function authorizeManagement(
        Request $request,
        Account $account,
        Event $event,
        ?EventPromoCode $promoCode = null,
    ): void {
        $this->ensureScope($account, $event, $promoCode);
        abort_unless($request->user()?->can('manageEvents', $account), 403);
    }
}
