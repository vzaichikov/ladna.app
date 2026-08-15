<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\DeleteFestivalEntry;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalScheduleSlotType;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Http\Requests\DeleteFestivalEntryRequest;
use App\Http\Requests\FestivalTicketRefundRequest;
use App\Http\Requests\FestivalTicketVoidRequest;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalAnnouncement;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\User;
use App\Support\Festivals\FestivalActivityLogPresenter;
use App\Support\Festivals\FestivalApplicationIndex;
use App\Support\Festivals\FestivalProgramOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\Telegram\Alerts\QueueFestivalOwnerTelegramAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalWorkspaceController extends Controller
{
    public function __construct(
        private FestivalWorkspaceAccess $workspaceAccess,
        private FestivalProgramOrder $programOrder,
        private FestivalApplicationIndex $applicationIndex,
    ) {}

    public function applications(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['registrations'] || $permissions['finance'], 403);

        $filterData = $this->applicationIndex->filterData($request, $festivalEdition);
        $filters = $filterData['filters'];
        $entries = $this->applicationIndex->query($festivalEdition, $filters, $permissions['registrations'])
            ->with(['category.direction', 'steps.charges', 'steps.workflowStep'])
            ->when($permissions['registrations'], fn (Builder $query) => $query->with('portalUser'))
            ->withCount([
                'requirements as blocking_requirements_count' => fn ($query) => $query
                    ->whereHas('definition', fn ($query) => $query->where('is_required', true))
                    ->whereNotIn('status', [FestivalRequirementStatus::Accepted->value, FestivalRequirementStatus::Waived->value]),
                'charges as blocking_charges_count' => fn ($query) => $query->whereNotIn('status', [FestivalChargeStatus::Paid->value, FestivalChargeStatus::Cancelled->value]),
                'scheduleSlots as performance_slots_count' => fn ($query) => $query->where('type', 'performance'),
                'scheduleSlots as scheduled_performance_slots_count' => fn ($query) => $query
                    ->where('type', 'performance')
                    ->whereNotNull('starts_at')
                    ->whereNotNull('ends_at'),
            ])
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.applications', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'entries' => $entries,
            'categories' => $filterData['categories'],
            'currentStepGroups' => $filterData['current_steps']->groupBy('festival_workflow_id'),
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn (string $value): bool => $value !== ''),
            'queueKeys' => $this->applicationIndex->queueKeys(),
            'queueCounts' => $this->applicationIndex->queueCounts($festivalEdition, $filters, $permissions['registrations']),
        ]);
    }

    public function application(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, DeleteFestivalEntry $deleteEntry, FestivalActivityLogPresenter $activityPresenter): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['registrations'] || $permissions['finance'], 403);
        abort_unless($festivalEntry->account_id === $account->id && $festivalEntry->festival_edition_id === $festivalEdition->id, 404);

        $requestedTab = $request->query('tab');
        $tab = $permissions['registrations'] && $requestedTab === 'history' ? 'history' : 'details';
        $activityHistory = null;

        if ($tab === 'history') {
            $activityHistory = FestivalActivityLog::query()
                ->where('account_id', $account->id)
                ->where('festival_edition_id', $festivalEdition->id)
                ->where('festival_entry_id', $festivalEntry->id)
                ->with(['actorUser:id,name', 'actorPortalUser:id,first_name,last_name,email,phone'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'history_page')
                ->withQueryString();
            $activityHistory->setCollection($activityHistory->getCollection()->map(
                fn (FestivalActivityLog $activity): array => $activityPresenter->present(
                    $activity,
                    $festivalEdition->timezone,
                    $permissions['finance'],
                ),
            ));
        } else {
            $this->loadApplication($festivalEntry, $festivalEdition, $permissions);
        }

        return view('festivals.staff.application', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'entry' => $festivalEntry,
            'tab' => $tab,
            'activityHistory' => $activityHistory,
            'canDeleteApplication' => $tab === 'details' && $permissions['manage'],
            'deleteApplicationRequiresPaymentConfirmation' => $tab === 'details'
                && $permissions['manage']
                && $deleteEntry->requiresPaymentConfirmation($festivalEntry),
            'deleteApplicationConfirmationPhrase' => DeleteFestivalEntry::CONFIRMATION_PHRASE,
            'categories' => $tab === 'details'
                ? $festivalEdition->categories()->with('direction')->orderBy('name')->get()
                : collect(),
            'currentStep' => $tab === 'details' && $permissions['registrations']
                ? $festivalEntry->steps->first(fn ($step): bool => $step->status !== FestivalEntryStepStatus::Approved)
                : null,
        ]);
    }

    public function destroyApplication(DeleteFestivalEntryRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, DeleteFestivalEntry $deleteEntry): RedirectResponse
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['manage'], 403);
        abort_unless($festivalEntry->festival_edition_id === $festivalEdition->id, 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $deleteEntry->execute($festivalEntry, $actor, $request->paymentDeletionConfirmed());
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            return back()->withErrors([
                'festival_application' => __('app.festival_application_delete_linked'),
            ]);
        }

        return redirect()->route('dashboard.accounts.festivals.applications', [$account, $festivalEdition])
            ->with('status', __('app.festival_application_deleted'));
    }

    public function performances(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['registrations'], 403);
        [$categories, $filters] = $this->entryIndexFilters($request, $festivalEdition);
        $entries = $this->entryIndexQuery($festivalEdition, $filters, true)
            ->where('status', FestivalEntryStatus::Accepted->value)
            ->latest('accepted_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.performances', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'entries' => $entries,
            'categories' => $categories,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['category'] !== '',
        ]);
    }

    public function performance(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['registrations'], 403);
        abort_unless($festivalEntry->festival_edition_id === $festivalEdition->id && $festivalEntry->status === FestivalEntryStatus::Accepted, 404);
        $this->loadApplication($festivalEntry, $festivalEdition, $permissions);

        return view('festivals.staff.performance', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'entry' => $festivalEntry,
        ]);
    }

    public function program(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['schedule'], 403);

        $stages = $festivalEdition->stages()->withCount('slots')->get();
        $activeStage = $request->filled('scene')
            ? $stages->firstWhere('id', $request->integer('scene'))
            : $stages->first();
        abort_if($request->filled('scene') && ! $activeStage, 404);
        $programItems = $activeStage
            ? FestivalScheduleSlot::query()
                ->where('festival_stage_id', $activeStage->id)
                ->where('festival_edition_id', $festivalEdition->id)
                ->with(['entry:id,festival_edition_id,code,entry_name', 'category:id,festival_edition_id,name'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
            : collect();
        $entries = FestivalEntry::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->whereIn('status', [FestivalEntryStatus::Submitted->value, FestivalEntryStatus::UnderReview->value, FestivalEntryStatus::ChangesPending->value, FestivalEntryStatus::Accepted->value])
            ->orderBy('entry_name')
            ->get(['id', 'festival_edition_id', 'code', 'entry_name']);
        $categories = $festivalEdition->categories()->orderBy('name')->get(['id', 'festival_edition_id', 'name']);
        $acceptedEntryIds = FestivalEntry::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->where('status', FestivalEntryStatus::Accepted->value)
            ->pluck('id');
        $assignedEntryIds = FestivalScheduleSlot::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->where('type', FestivalScheduleSlotType::Performance->value)
            ->whereIn('festival_entry_id', $acceptedEntryIds)
            ->pluck('festival_entry_id')
            ->unique();
        $generationStats = [
            'current_items' => $programItems->count(),
            'accepted_performances' => $acceptedEntryIds->count(),
            'missing_performances' => $acceptedEntryIds->diff($assignedEntryIds)->count(),
            'assigned_elsewhere' => $activeStage
                ? FestivalScheduleSlot::query()
                    ->where('festival_edition_id', $festivalEdition->id)
                    ->where('festival_stage_id', '!=', $activeStage->id)
                    ->where('type', FestivalScheduleSlotType::Performance->value)
                    ->whereIn('festival_entry_id', $acceptedEntryIds)
                    ->distinct()
                    ->count('festival_entry_id')
                : 0,
        ];

        return view('festivals.staff.program', compact('account', 'festivalEdition', 'entries', 'categories', 'stages', 'activeStage', 'programItems', 'generationStats') + [
            'edition' => $festivalEdition,
            'programTree' => $this->programOrder->tree($programItems),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function tickets(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['finance'] || $permissions['ticket_check_in'], 403);
        $requestedTab = $request->query('tab');
        $tab = $permissions['finance'] && in_array($requestedTab, ['types', 'sold'], true) ? (string) $requestedTab : 'types';
        $revenueByCurrency = $permissions['finance']
            ? $festivalEdition->ticketOrders()
                ->where('source', FestivalTicketOrderSource::Checkout->value)
                ->where('status', FestivalTicketOrderStatus::Paid->value)
                ->selectRaw('currency, sum(amount_cents) as aggregate')
                ->groupBy('currency')
                ->orderBy('currency')
                ->pluck('aggregate', 'currency')
                ->map(fn (mixed $amount): int => (int) $amount)
            : collect();

        $admissionReport = [
            'paid_orders' => $permissions['finance'] ? $festivalEdition->ticketOrders()->where('source', FestivalTicketOrderSource::Checkout->value)->where('status', FestivalTicketOrderStatus::Paid->value)->count() : null,
            'revenue_by_currency' => $revenueByCurrency,
            'tickets' => $festivalEdition->tickets()->count(),
            'checked_in' => $festivalEdition->tickets()->where('is_checked_in', true)->count(),
        ];
        $admissionTypes = null;
        $admissionAvailability = collect();
        $tickets = null;
        $refundRequiredOrders = collect();
        $ticketTypeOptions = collect();
        $filters = ['q' => '', 'status' => '', 'type' => '', 'source' => ''];

        if ($permissions['finance'] && $tab === 'types') {
            $filters = [
                'q' => $request->string('q')->trim()->toString(),
                'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : '',
                'type' => '',
                'source' => '',
            ];
            $admissionTypes = FestivalAdmissionType::query()
                ->where('festival_edition_id', $festivalEdition->id)
                ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', '%'.$filters['q'].'%')
                    ->orWhere('description', 'like', '%'.$filters['q'].'%')))
                ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->paginate(20)
                ->withQueryString();

            $admissionAvailability = $this->admissionAvailability($admissionTypes->getCollection());
        } elseif ($permissions['finance'] && $tab === 'sold') {
            $validTicketStatuses = collect(FestivalTicketStatus::cases())->pluck('value')->all();
            $filters = [
                'q' => $request->string('q')->trim()->toString(),
                'status' => in_array($request->query('status'), $validTicketStatuses, true) ? (string) $request->query('status') : '',
                'type' => $request->integer('type') > 0 ? (string) $request->integer('type') : '',
                'source' => in_array($request->query('source'), collect(FestivalTicketOrderSource::cases())->pluck('value')->all(), true) ? (string) $request->query('source') : '',
            ];
            $ticketTypeOptions = $festivalEdition->admissionTypes()->orderBy('name')->get(['id', 'festival_edition_id', 'name']);
            $tickets = FestivalTicket::query()
                ->where('festival_edition_id', $festivalEdition->id)
                ->with(['admissionType', 'orderItem', 'order.fiscalReceipt', 'order.tickets', 'order.issuer'])
                ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('code', 'like', '%'.$filters['q'].'%')
                    ->orWhere('holder_name', 'like', '%'.$filters['q'].'%')
                    ->orWhereHas('order', fn ($query) => $query
                        ->where('order_id', 'like', '%'.$filters['q'].'%')
                        ->orWhere('buyer_name', 'like', '%'.$filters['q'].'%')
                        ->orWhere('buyer_email', 'like', '%'.$filters['q'].'%')
                        ->orWhere('buyer_phone', 'like', '%'.$filters['q'].'%')
                        ->orWhere('gateway_invoice_id', 'like', '%'.$filters['q'].'%')
                        ->orWhere('gateway_payment_id', 'like', '%'.$filters['q'].'%'))))
                ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
                ->when($filters['type'] !== '', fn ($query) => $query->where('festival_admission_type_id', (int) $filters['type']))
                ->when($filters['source'] !== '', fn ($query) => $query->whereHas('order', fn ($orders) => $orders->where('source', $filters['source'])))
                ->latest('id')
                ->paginate(20)
                ->withQueryString();
            $refundRequiredOrders = $festivalEdition->ticketOrders()
                ->where('source', FestivalTicketOrderSource::Checkout->value)
                ->where('status', FestivalTicketOrderStatus::PaidRequiresRefund->value)
                ->with('items')
                ->latest('paid_at')
                ->get();
        }

        return view('festivals.staff.tickets', compact('account', 'tab', 'admissionTypes', 'admissionAvailability', 'admissionReport', 'tickets', 'ticketTypeOptions', 'filters', 'refundRequiredOrders') + [
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function refundTicketOrder(FestivalTicketRefundRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalTicketOrder $festivalTicketOrder): RedirectResponse
    {
        $this->assertTicketOrderScope($account, $festivalEdition, $festivalTicketOrder);

        DB::transaction(function () use ($festivalTicketOrder, $request): void {
            $order = FestivalTicketOrder::query()->whereKey($festivalTicketOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->source === FestivalTicketOrderSource::Checkout, 422);
            abort_unless(in_array($order->status, [FestivalTicketOrderStatus::Paid, FestivalTicketOrderStatus::PaidRequiresRefund], true), 422);
            $order->forceFill([
                'status' => FestivalTicketOrderStatus::Refunded,
                'refunded_by' => $request->user()->id,
                'refunded_at' => now(),
                'refund_reason' => $request->validated('reason'),
            ])->save();
            $order->tickets()->update([
                'status' => FestivalTicketStatus::Refunded->value,
                'is_checked_in' => false,
                'checked_in_at' => null,
            ]);
            $order->tickets()->each(fn (FestivalTicket $ticket) => $ticket->streamEntitlement()->delete());
        }, 3);

        return back()->with('status', __('app.festival_refund_recorded'));
    }

    public function voidTicket(FestivalTicketVoidRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalTicket $festivalTicket): RedirectResponse
    {
        abort_unless($festivalTicket->account_id === $account->id && $festivalTicket->festival_edition_id === $festivalEdition->id, 404);

        DB::transaction(function () use ($festivalTicket, $request): void {
            $ticket = FestivalTicket::query()->whereKey($festivalTicket->id)->lockForUpdate()->firstOrFail();
            abort_unless($ticket->status === FestivalTicketStatus::Valid, 422);
            $ticket->forceFill([
                'status' => FestivalTicketStatus::Voided,
                'is_checked_in' => false,
                'checked_in_at' => null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
                'void_reason' => $request->validated('reason'),
            ])->save();
            $ticket->streamEntitlement()->delete();
        }, 3);

        return back()->with('status', __('app.festival_ticket_voided'));
    }

    public function communication(Request $request, Account $account, FestivalEdition $festivalEdition, QueueFestivalOwnerTelegramAlert $ownerTelegramAlerts): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['manage'], 403);
        $requestedTab = $request->query('tab');
        $tab = in_array($requestedTab, ['history', 'announcements', 'settings'], true) ? (string) $requestedTab : 'history';
        $settings = collect();
        $notificationStatistics = collect();
        $announcements = null;
        $notifications = null;
        $notificationTypes = FestivalNotificationType::cases();
        $filters = ['q' => '', 'type' => '', 'channel' => '', 'status' => ''];

        if ($tab === 'history') {
            $types = collect(FestivalNotificationType::cases())->pluck('value')->all();
            $channels = collect(FestivalNotificationChannel::cases())->pluck('value')->all();
            $statuses = collect(FestivalNotificationStatus::cases())->pluck('value')->all();
            $filters = [
                'q' => $request->string('q')->trim()->toString(),
                'type' => in_array($request->query('type'), $types, true) ? (string) $request->query('type') : '',
                'channel' => in_array($request->query('channel'), $channels, true) ? (string) $request->query('channel') : '',
                'status' => in_array($request->query('status'), $statuses, true) ? (string) $request->query('status') : '',
            ];
            $notificationStatistics = FestivalNotification::query()
                ->where('festival_edition_id', $festivalEdition->id)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $notifications = FestivalNotification::query()
                ->where('festival_edition_id', $festivalEdition->id)
                ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('recipient_name', 'like', '%'.$filters['q'].'%')
                    ->orWhere('recipient_email', 'like', '%'.$filters['q'].'%')
                    ->orWhere('recipient_phone', 'like', '%'.$filters['q'].'%')
                    ->orWhere('subject', 'like', '%'.$filters['q'].'%')
                    ->orWhere('text', 'like', '%'.$filters['q'].'%')))
                ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
                ->when($filters['channel'] !== '', fn ($query) => $query->where('channel', $filters['channel']))
                ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
                ->latest('id')
                ->paginate(20)
                ->withQueryString();
        } elseif ($tab === 'announcements') {
            $announcements = FestivalAnnouncement::query()
                ->where('festival_edition_id', $festivalEdition->id)
                ->latest('id')
                ->paginate(20)
                ->withQueryString();
        } else {
            $settings = FestivalNotificationSetting::query()
                ->whereBelongsTo($account)
                ->get()
                ->keyBy(fn (FestivalNotificationSetting $setting): string => $setting->type->value);
        }

        return view('festivals.staff.communication', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'tab' => $tab,
            'notificationTypes' => $notificationTypes,
            'notificationSettings' => $settings,
            'connectedFestivalOwnerCount' => $tab === 'settings' ? $ownerTelegramAlerts->connectedOwnerCount($account) : 0,
            'notificationStatistics' => $notificationStatistics,
            'announcements' => $announcements,
            'notifications' => $notifications,
            'filters' => $filters,
        ]);
    }

    /**
     * @param  Collection<int, FestivalAdmissionType>  $admissionTypes
     * @return Collection<int, array{remaining: int, sold: int, held: int, current_price_cents: int, price_tier: string, locked: bool, has_history: bool}>
     */
    private function admissionAvailability(Collection $admissionTypes): Collection
    {
        $typeIds = $admissionTypes->pluck('id');
        $quantities = fn (array $statuses, bool $pendingOnly = false) => FestivalTicketOrderItem::query()
            ->whereIn('festival_admission_type_id', $typeIds)
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', $statuses)
                ->when($pendingOnly, fn ($query) => $query->where('expires_at', '>', now())))
            ->selectRaw('festival_admission_type_id, sum(quantity) as aggregate')
            ->groupBy('festival_admission_type_id')
            ->pluck('aggregate', 'festival_admission_type_id');
        $sold = $quantities([FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value]);
        $held = $quantities([FestivalTicketOrderStatus::Pending->value], true);
        $early = FestivalTicketOrderItem::query()
            ->whereIn('festival_admission_type_id', $typeIds)
            ->where('price_tier', 'early_bird')
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
            ->selectRaw('festival_admission_type_id, sum(quantity) as aggregate')
            ->groupBy('festival_admission_type_id')
            ->pluck('aggregate', 'festival_admission_type_id');
        $lockedIds = FestivalTicketOrderItem::query()
            ->whereIn('festival_admission_type_id', $typeIds)
            ->whereHas('order', fn ($query) => $query->whereIn('status', [FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value, FestivalTicketOrderStatus::Refunded->value]))
            ->pluck('festival_admission_type_id')
            ->unique();
        $historyIds = FestivalTicketOrderItem::query()->whereIn('festival_admission_type_id', $typeIds)->pluck('festival_admission_type_id')->unique();

        return $admissionTypes->mapWithKeys(function (FestivalAdmissionType $type) use ($sold, $held, $early, $lockedIds, $historyIds): array {
            $soldQuantity = (int) ($sold[$type->id] ?? 0);
            $heldQuantity = (int) ($held[$type->id] ?? 0);
            $earlyQuantity = (int) ($early[$type->id] ?? 0);
            $earlyAvailable = $type->early_bird_price_cents !== null
                && (! $type->early_bird_ends_at || $type->early_bird_ends_at->isFuture())
                && ($type->early_bird_quota === null || $earlyQuantity < $type->early_bird_quota);

            return [$type->id => [
                'remaining' => max(0, $type->inventory - $soldQuantity - $heldQuantity),
                'sold' => $soldQuantity,
                'held' => $heldQuantity,
                'current_price_cents' => $earlyAvailable ? (int) $type->early_bird_price_cents : $type->price_cents,
                'price_tier' => $earlyAvailable ? 'early_bird' : 'regular',
                'locked' => $lockedIds->contains($type->id),
                'has_history' => $historyIds->contains($type->id),
            ]];
        });
    }

    /**
     * @return array{Collection<int, FestivalCategory>, array{q: string, status: string, category: string}}
     */
    private function entryIndexFilters(Request $request, FestivalEdition $edition, bool $includeStatus = false): array
    {
        $categories = $edition->categories()->with('direction')->orderBy('name')->get();
        $requestedCategory = $request->integer('category');
        $statuses = collect(FestivalEntryStatus::cases())->pluck('value')->all();

        return [$categories, [
            'q' => $request->string('q')->trim()->toString(),
            'status' => $includeStatus && in_array($request->query('status'), $statuses, true) ? (string) $request->query('status') : '',
            'category' => $requestedCategory > 0 && $categories->contains('id', $requestedCategory) ? (string) $requestedCategory : '',
        ]];
    }

    /** @param array{q: string, status: string, category: string} $filters */
    private function entryIndexQuery(FestivalEdition $edition, array $filters, bool $includeApplicant): Builder
    {
        $searchTerms = preg_split('/\s+/u', $filters['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $query = FestivalEntry::query()
            ->where('festival_edition_id', $edition->id)
            ->when($searchTerms !== [], fn (Builder $query) => $query->where(function (Builder $query) use ($searchTerms, $includeApplicant): void {
                foreach ($searchTerms as $term) {
                    $search = '%'.$term.'%';
                    $query->where(function (Builder $query) use ($search, $includeApplicant): void {
                        $query->where('entry_name', 'like', $search)
                            ->orWhere('act_title', 'like', $search);
                        if ($includeApplicant) {
                            $query->orWhereHas('portalUser', fn (Builder $query) => $query
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('email', 'like', $search));
                        }
                    });
                }
            }))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['category'] !== '', fn (Builder $query) => $query->where('festival_category_id', (int) $filters['category']))
            ->with('category.direction');

        if ($includeApplicant) {
            $query->with('portalUser');
        }

        return $query;
    }

    /** @param array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} $permissions */
    private function loadApplication(FestivalEntry $entry, FestivalEdition $edition, array $permissions): void
    {
        abort_unless($entry->festival_edition_id === $edition->id, 404);
        $entry->load('category.direction');

        if ($permissions['registrations']) {
            $entry->load(['portalUser', 'participants', 'steps.workflowStep', 'requirements.definition', 'requirements.participant', 'requirements.submissions']);
        }

        if ($permissions['finance']) {
            $entry->load(['charges.paymentAttempts.fiscalReceipt', 'chargeAdjustments']);
        }
    }

    private function assertTicketOrderScope(Account $account, FestivalEdition $edition, FestivalTicketOrder $order): void
    {
        abort_unless($edition->account_id === $account->id
            && $order->account_id === $account->id
            && $order->festival_edition_id === $edition->id, 404);
    }

    /**
     * @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}
     */
    private function permissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);

        return $this->workspaceAccess->permissions($request->user(), $account, $edition);
    }
}
