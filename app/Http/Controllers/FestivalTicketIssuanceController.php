<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\IssueManualFestivalTickets;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderStatus;
use App\Http\Requests\IssueFestivalAudienceTicketsRequest;
use App\Http\Requests\IssueFestivalTicketRequest;
use App\Jobs\IssueFestivalJudgeTickets;
use App\Jobs\IssueFestivalParticipantTickets;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrderItem;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class FestivalTicketIssuanceController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly PhoneNumberNormalizer $phones,
    ) {}

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $filters = ['q' => $request->string('q')->trim()->toString()];
        $guests = FestivalPortalUser::query()
            ->whereBelongsTo($account)
            ->forRole(FestivalPortalRole::Guest)
            ->active()
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = '%'.$filters['q'].'%';
                $query->where(fn (Builder $identity) => $identity
                    ->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();
        $selectedGuest = FestivalPortalUser::query()
            ->whereBelongsTo($account)
            ->forRole(FestivalPortalRole::Guest)
            ->active()
            ->find($request->integer('selected_guest_id'));
        $admissionTypes = FestivalAdmissionType::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $festivalEdition->id)
            ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $participants = $this->acceptedParticipants($festivalEdition);
        $usableParticipantRegistrantIds = $this->usableParticipantRegistrantIds($participants, $account);
        $judgeGroups = $this->judgeGroups($festivalEdition);
        $participantIds = $participants->modelKeys();
        $participantIssuedIds = FestivalTicket::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->whereIn('festival_participant_id', $participantIds)
            ->pluck('festival_participant_id')
            ->filter()
            ->unique();
        $judgeAutomationKeys = $judgeGroups->pluck('automation_key');
        $judgeIssuedKeys = FestivalTicket::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->whereIn('automation_key', $judgeAutomationKeys)
            ->pluck('automation_key');

        return view('festivals.staff.ticket-issuance', [
            'account' => $account,
            'edition' => $festivalEdition,
            'guests' => $guests,
            'selectedGuest' => $selectedGuest,
            'admissionTypes' => $admissionTypes,
            'capacityByType' => $this->capacityByType($festivalEdition, $admissionTypes),
            'filters' => $filters,
            'participantStats' => [
                'eligible' => $participants->count(),
                'already_issued' => $participantIssuedIds->count(),
                'skipped' => $participants->reject(fn (FestivalParticipant $participant) => $usableParticipantRegistrantIds->contains($participant->festival_portal_user_id))->count(),
                'remaining' => $participants->whereNotIn('id', $participantIssuedIds)->filter(fn (FestivalParticipant $participant) => $usableParticipantRegistrantIds->contains($participant->festival_portal_user_id))->count(),
            ],
            'judgeStats' => [
                'eligible' => $judgeGroups->count(),
                'already_issued' => $judgeIssuedKeys->count(),
                'skipped' => $judgeGroups->where('is_usable', false)->count(),
                'remaining' => $judgeGroups->where('is_usable', true)->whereNotIn('automation_key', $judgeIssuedKeys)->count(),
            ],
            'workspacePermissions' => $permissions,
        ]);
    }

    public function store(
        IssueFestivalTicketRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        IssueManualFestivalTickets $issueTickets,
    ): RedirectResponse {
        $guest = FestivalPortalUser::query()->findOrFail($request->integer('festival_portal_user_id'));
        $admissionType = FestivalAdmissionType::query()->findOrFail($request->integer('festival_admission_type_id'));
        $order = $issueTickets->execute($festivalEdition, $guest, $admissionType, $request->user(), [[
            'holder_name' => $request->string('holder_name')->trim()->toString(),
        ]]);

        return redirect()->route('dashboard.accounts.festivals.tickets', [$account, $festivalEdition, 'tab' => 'sold'])
            ->with('status', __('app.festival_manual_ticket_issued', ['order' => $order?->order_id]));
    }

    public function storeAudience(IssueFestivalAudienceTicketsRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $admissionTypeId = $request->integer('festival_admission_type_id');
        $queued = $request->validated('audience') === 'participants'
            ? $this->dispatchParticipantJobs($festivalEdition, $account, $admissionTypeId, $request->user()->id)
            : $this->dispatchJudgeJobs($festivalEdition, $admissionTypeId, $request->user()->id);

        return back()->with('status', trans_choice('app.festival_manual_ticket_jobs_queued', $queued, ['count' => $queued]));
    }

    /** @return Collection<int, FestivalParticipant> */
    private function acceptedParticipants(FestivalEdition $edition): Collection
    {
        return FestivalParticipant::query()
            ->where('account_id', $edition->account_id)
            ->whereNull('archived_at')
            ->whereHas('entries', fn ($query) => $query
                ->where('festival_edition_id', $edition->id)
                ->where('status', FestivalEntryStatus::Accepted->value))
            ->with('portalUser')
            ->orderBy('id')
            ->get()
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, array{email: string, phone_normalized: string|null, assignment_ids: array<int, int>, automation_key: string, is_usable: bool}> */
    private function judgeGroups(FestivalEdition $edition): Collection
    {
        $assignments = FestivalJudgeAssignment::query()
            ->where('account_id', $edition->account_id)
            ->where('festival_edition_id', $edition->id)
            ->where('is_active', true)
            ->with(['portalUser', 'user'])
            ->orderBy('id')
            ->get();
        $groups = collect();
        $skippedPosition = 0;

        foreach ($assignments as $assignment) {
            $email = $assignment->portalUser?->role === FestivalPortalRole::Judge && $assignment->portalUser->is_active
                ? $assignment->portalUser->email
                : $assignment->user?->email;
            $normalizedEmail = FestivalPortalUser::normalizeEmail((string) $email);
            $source = $assignment->portalUser?->role === FestivalPortalRole::Judge && $assignment->portalUser->is_active
                ? $assignment->portalUser
                : $assignment->user;
            $normalizedPhone = $this->phones->normalize($source?->phone, $edition->account->country_code);
            $usable = $normalizedEmail !== '' && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) !== false;
            $key = $usable ? $normalizedEmail : 'skipped:'.$skippedPosition++;
            $current = $groups->get($key, [
                'email' => $normalizedEmail,
                'phone_normalized' => $normalizedPhone,
                'assignment_ids' => [],
                'automation_key' => $usable ? 'judge:'.hash('sha256', $normalizedEmail) : $key,
                'is_usable' => $usable,
            ]);
            $current['assignment_ids'][] = $assignment->id;
            $groups->put($key, $current);
        }

        $groups = $groups->values();
        $emails = $groups->where('is_usable', true)->pluck('email')->filter()->unique();
        $phones = $groups->where('is_usable', true)->pluck('phone_normalized')->filter()->unique();
        $guests = FestivalPortalUser::query()
            ->where('account_id', $edition->account_id)
            ->where('role', FestivalPortalRole::Guest->value)
            ->where(fn (Builder $query) => $query->whereIn('email_normalized', $emails)->orWhereIn('phone_normalized', $phones))
            ->get();
        $guestsByEmail = $guests->keyBy('email_normalized');
        $guestsByPhone = $guests->whereNotNull('phone_normalized')->keyBy('phone_normalized');

        return $groups->map(function (array $group) use ($guestsByEmail, $guestsByPhone): array {
            if (! $group['is_usable']) {
                return $group;
            }

            $guest = $guestsByEmail->get($group['email']);
            $group['is_usable'] = $guest
                ? $guest->is_active
                : blank($group['phone_normalized']) || ! $guestsByPhone->has($group['phone_normalized']);

            return $group;
        });
    }

    /**
     * @param  Collection<int, FestivalParticipant>  $participants
     * @return Collection<int, int>
     */
    private function usableParticipantRegistrantIds(Collection $participants, Account $account): Collection
    {
        $registrants = $participants->pluck('portalUser')->filter()->unique('id');
        $emails = $registrants->pluck('email_normalized')->filter()->unique();
        $phones = $registrants->pluck('phone_normalized')->filter()->unique();
        $guests = FestivalPortalUser::query()
            ->where('account_id', $account->id)
            ->where('role', FestivalPortalRole::Guest->value)
            ->where(fn (Builder $query) => $query->whereIn('email_normalized', $emails)->orWhereIn('phone_normalized', $phones))
            ->get();
        $guestsByEmail = $guests->keyBy('email_normalized');
        $guestsByPhone = $guests->whereNotNull('phone_normalized')->keyBy('phone_normalized');

        return $registrants->filter(function (FestivalPortalUser $registrant) use ($guestsByEmail, $guestsByPhone): bool {
            if (! $registrant->is_active || blank($registrant->email) || filter_var($registrant->email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }

            $guest = $guestsByEmail->get($registrant->email_normalized);
            if ($guest) {
                return $guest->is_active;
            }

            return blank($registrant->phone_normalized) || ! $guestsByPhone->has($registrant->phone_normalized);
        })->pluck('id')->values();
    }

    /** @param Collection<int, FestivalAdmissionType> $admissionTypes */
    private function capacityByType(FestivalEdition $edition, Collection $admissionTypes): Collection
    {
        $purchase = $edition->purchase()->with('package')->first();
        $packageRemaining = PHP_INT_MAX;
        if ($purchase) {
            $heldQuantity = (int) FestivalTicketOrderItem::query()
                ->whereHas('order', fn ($query) => $query
                    ->where('festival_edition_id', $edition->id)
                    ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                    ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                ->sum('quantity');
            $packageRemaining = max(0, $purchase->package->max_tickets - $heldQuantity);
        }

        return $admissionTypes->mapWithKeys(fn (FestivalAdmissionType $type): array => [
            $type->id => min($type->remainingQuantity(), $packageRemaining),
        ]);
    }

    private function dispatchParticipantJobs(FestivalEdition $edition, Account $account, int $admissionTypeId, int $actorId): int
    {
        $participants = $this->acceptedParticipants($edition);
        $usableRegistrantIds = $this->usableParticipantRegistrantIds($participants, $account);
        $issuedIds = FestivalTicket::query()->where('festival_edition_id', $edition->id)->whereNotNull('festival_participant_id')->pluck('festival_participant_id');
        $registrantIds = $participants
            ->whereNotIn('id', $issuedIds)
            ->filter(fn (FestivalParticipant $participant) => $usableRegistrantIds->contains($participant->festival_portal_user_id))
            ->pluck('festival_portal_user_id')
            ->unique();

        foreach ($registrantIds as $registrantId) {
            IssueFestivalParticipantTickets::dispatch($edition->id, (int) $registrantId, $admissionTypeId, $actorId);
        }

        return $registrantIds->count();
    }

    private function dispatchJudgeJobs(FestivalEdition $edition, int $admissionTypeId, int $actorId): int
    {
        $groups = $this->judgeGroups($edition)->where('is_usable', true);
        $issuedKeys = FestivalTicket::query()->where('festival_edition_id', $edition->id)->whereNotNull('automation_key')->pluck('automation_key');
        $groups = $groups->whereNotIn('automation_key', $issuedKeys);

        foreach ($groups as $group) {
            IssueFestivalJudgeTickets::dispatch($edition->id, $group['email'], $group['assignment_ids'], $admissionTypeId, $actorId);
        }

        return $groups->count();
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function financePermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['finance'], 403);

        return $permissions;
    }
}
