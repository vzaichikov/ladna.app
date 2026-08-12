<?php

namespace App\Http\Controllers;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\Account;
use App\Models\FestivalAnnouncement;
use App\Models\FestivalCategory;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalWorkspaceController extends Controller
{
    public function __construct(private FestivalWorkspaceAccess $workspaceAccess) {}

    public function applications(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['registrations'] || $permissions['finance'], 403);

        $entriesQuery = FestivalEntry::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->with('category.direction')
            ->withCount([
                'requirements as blocking_requirements_count' => fn ($query) => $query
                    ->whereHas('definition', fn ($query) => $query->where('is_required', true))
                    ->whereNotIn('status', [FestivalRequirementStatus::Accepted->value, FestivalRequirementStatus::Waived->value]),
                'charges as blocking_charges_count' => fn ($query) => $query->whereNotIn('status', [FestivalChargeStatus::Paid->value, FestivalChargeStatus::Cancelled->value]),
                'scheduleSlots as performance_slots_count' => fn ($query) => $query->where('type', 'performance'),
            ])
            ->latest('submitted_at')
            ->latest('id');

        if ($permissions['registrations']) {
            $entriesQuery->with(['portalUser', 'participants', 'steps.workflowStep', 'steps.requirements.definition', 'steps.requirements.participant', 'steps.requirements.submissions', 'requirements.definition', 'requirements.participant', 'requirements.submissions']);
        }

        if ($permissions['finance']) {
            $entriesQuery->with(['steps.charges.paymentAttempts', 'charges.paymentAttempts', 'chargeAdjustments']);
        }

        $entries = $entriesQuery->paginate(50)->withQueryString();
        $entryStatistics = FestivalEntry::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $categories = $festivalEdition->categories()
            ->withCount('entries')
            ->with('direction')
            ->orderBy('name')
            ->get();
        $entryTable = (new FestivalEntry)->getTable();
        $categoryTable = (new FestivalCategory)->getTable();
        $directionTable = (new FestivalDirection)->getTable();
        $directionStatistics = FestivalEntry::query()
            ->where($entryTable.'.festival_edition_id', $festivalEdition->id)
            ->leftJoin($categoryTable, $categoryTable.'.id', '=', $entryTable.'.festival_category_id')
            ->leftJoin($directionTable, $directionTable.'.id', '=', $categoryTable.'.festival_direction_id')
            ->whereNotNull($directionTable.'.name')
            ->select($directionTable.'.name as label')
            ->selectRaw('count(*) as aggregate')
            ->groupBy($directionTable.'.code', $directionTable.'.name')
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => ['label' => (string) $row->label, 'count' => (int) $row->aggregate]);

        return view('festivals.staff.applications', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'entries' => $entries,
            'categories' => $categories,
            'entryStatistics' => $entryStatistics,
            'categoryStatistics' => $categories->map(fn ($category): array => ['label' => $category->name, 'count' => $category->entries_count]),
            'directionStatistics' => $directionStatistics,
        ]);
    }

    public function program(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['schedule'], 403);

        $festivalEdition->load([
            'stages' => fn (HasMany $query) => $query->with(['slots' => fn (HasMany $query) => $query->with('entry')->orderBy('starts_at')]),
        ]);
        $entries = FestivalEntry::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->whereIn('status', [FestivalEntryStatus::Submitted->value, FestivalEntryStatus::UnderReview->value, FestivalEntryStatus::Accepted->value])
            ->orderBy('entry_name')
            ->get(['id', 'festival_edition_id', 'code', 'entry_name']);

        return view('festivals.staff.program', compact('account', 'festivalEdition', 'entries') + [
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function tickets(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['finance'] || $permissions['ticket_check_in'], 403);

        $admissionTypes = $festivalEdition->admissionTypes()->get();
        $admissionTypeIds = $admissionTypes->pluck('id');
        $activeQuantities = FestivalTicketOrderItem::query()
            ->whereIn('festival_admission_type_id', $admissionTypeIds)
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
            ->selectRaw('festival_admission_type_id, sum(quantity) as aggregate')
            ->groupBy('festival_admission_type_id')
            ->pluck('aggregate', 'festival_admission_type_id');
        $earlyQuantities = FestivalTicketOrderItem::query()
            ->whereIn('festival_admission_type_id', $admissionTypeIds)
            ->where('price_tier', 'early_bird')
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value])
                ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
            ->selectRaw('festival_admission_type_id, sum(quantity) as aggregate')
            ->groupBy('festival_admission_type_id')
            ->pluck('aggregate', 'festival_admission_type_id');
        $admissionAvailability = $admissionTypes->mapWithKeys(function ($admissionType) use ($activeQuantities, $earlyQuantities): array {
            $activeQuantity = (int) ($activeQuantities[$admissionType->id] ?? 0);
            $earlySold = (int) ($earlyQuantities[$admissionType->id] ?? 0);
            $earlyAvailable = $admissionType->early_bird_price_cents !== null
                && (! $admissionType->early_bird_ends_at || $admissionType->early_bird_ends_at->isFuture())
                && ($admissionType->early_bird_quota === null || $earlySold < $admissionType->early_bird_quota);

            return [$admissionType->id => [
                'remaining' => max(0, $admissionType->inventory - $activeQuantity),
                'current_price_cents' => $earlyAvailable ? $admissionType->early_bird_price_cents : $admissionType->price_cents,
                'price_tier' => $earlyAvailable ? 'early_bird' : 'regular',
            ]];
        });

        $admissionReport = [
            'paid_orders' => $permissions['finance'] ? $festivalEdition->ticketOrders()->where('status', FestivalTicketOrderStatus::Paid->value)->count() : null,
            'revenue_cents' => $permissions['finance'] ? (int) $festivalEdition->ticketOrders()->where('status', FestivalTicketOrderStatus::Paid->value)->sum('amount_cents') : null,
            'tickets' => $festivalEdition->tickets()->count(),
            'checked_in' => $festivalEdition->tickets()->where('is_checked_in', true)->count(),
        ];
        $orders = $permissions['finance']
            ? FestivalTicketOrder::query()->where('festival_edition_id', $festivalEdition->id)->with('items')->withCount('tickets')->latest()->paginate(30)->withQueryString()
            : null;

        return view('festivals.staff.tickets', compact('account', 'admissionTypes', 'admissionAvailability', 'admissionReport', 'orders') + [
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function communication(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['manage'], 403);

        $settings = FestivalNotificationSetting::query()
            ->whereBelongsTo($account)
            ->get()
            ->keyBy(fn (FestivalNotificationSetting $setting): string => $setting->type->value);
        $notificationStatistics = FestivalNotification::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('festivals.staff.communication', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'notificationTypes' => FestivalNotificationType::cases(),
            'notificationSettings' => $settings,
            'notificationStatistics' => $notificationStatistics,
            'announcements' => FestivalAnnouncement::query()->where('festival_edition_id', $festivalEdition->id)->latest()->paginate(20, ['*'], 'announcements_page')->withQueryString(),
            'notifications' => FestivalNotification::query()->where('festival_edition_id', $festivalEdition->id)->latest()->paginate(30, ['*'], 'notifications_page')->withQueryString(),
        ]);
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
