<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalProfilePhoto;
use App\Actions\Festivals\SyncFestivalProfileParticipant;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalRegistrantType;
use App\Enums\FestivalTicketStatus;
use App\Http\Requests\StoreFestivalPortalUserRequest;
use App\Http\Requests\UpdateFestivalPortalUserRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalPortalUserController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        $allowedTabs = array_values(array_filter([
            $permissions['registrations'] ? 'participants' : null,
            $permissions['manage'] ? 'judges' : null,
            $permissions['manage'] || $permissions['finance'] ? 'guests' : null,
        ]));
        abort_if($allowedTabs === [], 403);

        $tab = $request->query('tab');
        $tab = is_string($tab) && in_array($tab, ['participants', 'judges', 'guests'], true) ? $tab : $allowedTabs[0];
        abort_unless(in_array($tab, $allowedTabs, true), 403);
        $role = match ($tab) {
            'judges' => FestivalPortalRole::Judge,
            'guests' => FestivalPortalRole::Guest,
            default => FestivalPortalRole::Registrant,
        };
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
        ];

        $portalUsers = FestivalPortalUser::query()
            ->whereBelongsTo($account)
            ->forRole($role)
            ->when($role === FestivalPortalRole::Registrant, fn (Builder $query) => $query
                ->withCount('participants')
                ->withCount(['entries as current_edition_entries_count' => fn (Builder $entries) => $entries->where('festival_edition_id', $festivalEdition->id)]))
            ->when($role === FestivalPortalRole::Judge, fn (Builder $query) => $query
                ->withCount(['judgeAssignments as current_edition_assignments_count' => fn (Builder $assignments) => $assignments
                    ->where('festival_edition_id', $festivalEdition->id)
                    ->where('is_active', true)]))
            ->when($role === FestivalPortalRole::Guest, fn (Builder $query) => $query
                ->withCount(['ticketOrders as current_edition_orders_count' => fn (Builder $orders) => $orders
                    ->where('festival_edition_id', $festivalEdition->id)])
                ->withCount(['tickets as current_edition_valid_tickets_count' => fn (Builder $tickets) => $tickets
                    ->where('festival_tickets.festival_edition_id', $festivalEdition->id)
                    ->where('festival_tickets.status', FestivalTicketStatus::Valid->value)]))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = '%'.$filters['q'].'%';
                $query->where(fn (Builder $identity) => $identity
                    ->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhere('studio_name', 'like', $search));
            })
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.users.index', [
            'account' => $account,
            'edition' => $festivalEdition,
            'portalUsers' => $portalUsers,
            'tab' => $tab,
            'allowedTabs' => $allowedTabs,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition, string $role): View
    {
        $portalRole = $this->role($role);
        $permissions = $this->permissions($request, $account, $festivalEdition);
        $this->authorizeRole($portalRole, $permissions);

        return $this->formView($account, $festivalEdition, new FestivalPortalUser([
            'role' => $portalRole,
            'registrant_type' => $portalRole === FestivalPortalRole::Registrant ? FestivalRegistrantType::AdultAthlete : null,
            'is_active' => true,
            'locale' => $account->default_language,
        ]), $permissions, $request->query('return_to') === 'ticket-issuance' ? 'ticket-issuance' : null);
    }

    public function store(StoreFestivalPortalUserRequest $request, Account $account, FestivalEdition $festivalEdition, string $role, SyncFestivalProfileParticipant $syncParticipant, SaveFestivalProfilePhoto $saveProfilePhoto): RedirectResponse
    {
        $portalRole = $this->role($role);
        $permissions = $this->permissions($request, $account, $festivalEdition);
        $this->authorizeRole($portalRole, $permissions);
        $data = $request->validated();
        $photo = Arr::pull($data, 'photo');
        Arr::pull($data, 'remove_photo');
        $dateOfBirth = Arr::pull($data, 'date_of_birth');
        $portalUser = DB::transaction(function () use ($account, $portalRole, $data, $dateOfBirth, $syncParticipant, $saveProfilePhoto, $photo): FestivalPortalUser {
            $portalUser = FestivalPortalUser::query()->create([
                'account_id' => $account->id,
                'role' => $portalRole,
                ...Arr::except($data, ['password_confirmation']),
                'registrant_type' => $portalRole === FestivalPortalRole::Registrant
                    ? ($data['registrant_type'] ?? FestivalRegistrantType::AdultAthlete)
                    : null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $syncParticipant->execute($portalUser, $dateOfBirth);

            if ($portalRole === FestivalPortalRole::Registrant && $photo) {
                $portalUser = $saveProfilePhoto->execute($portalUser, $photo, false, static function (FestivalPortalUser $lockedPortalUser): void {});
            }

            return $portalUser;
        }, 3);

        if ($portalRole === FestivalPortalRole::Guest && $request->string('return_to')->toString() === 'ticket-issuance') {
            return redirect()->route('dashboard.accounts.festivals.tickets.issue', [$account, $festivalEdition, 'selected_guest_id' => $portalUser->id])
                ->with('status', __('app.festival_user_saved'));
        }

        return $this->redirectToEdit($account, $festivalEdition, $portalUser);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser): View|RedirectResponse
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        $this->assertPortalUser($account, $festivalPortalUser);
        $this->authorizeRole($festivalPortalUser->role, $permissions);

        if ($festivalPortalUser->role === FestivalPortalRole::Registrant && $request->query('tab') === 'notifications') {
            $page = max(1, $request->integer('notifications_page', 1));

            return redirect()->route('dashboard.accounts.festivals.users.notifications', [
                $account,
                $festivalEdition,
                $festivalPortalUser,
                ...($page > 1 ? ['page' => $page] : []),
            ]);
        }

        $festivalPortalUser->load([
            'profileParticipant',
            'judgeAssignments' => fn ($query) => $query->with('edition')->latest(),
        ]);

        return $this->formView($account, $festivalEdition, $festivalPortalUser, $permissions);
    }

    public function notifications(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        $this->assertPortalUser($account, $festivalPortalUser);
        abort_unless($festivalPortalUser->role === FestivalPortalRole::Registrant, 404);
        $this->authorizeRole($festivalPortalUser->role, $permissions);

        $festivalNotifications = $festivalPortalUser->festivalNotifications()
            ->where('account_id', $account->id)
            ->with([
                'edition:id,account_id,title,timezone',
                'entry:id,account_id,festival_edition_id,code,entry_name',
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.users.notifications', [
            'account' => $account,
            'edition' => $festivalEdition,
            'portalUser' => $festivalPortalUser,
            'festivalNotifications' => $festivalNotifications,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function update(UpdateFestivalPortalUserRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, SyncFestivalProfileParticipant $syncParticipant, SaveFestivalProfilePhoto $saveProfilePhoto): RedirectResponse
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        $this->assertPortalUser($account, $festivalPortalUser);
        $this->authorizeRole($festivalPortalUser->role, $permissions);
        $data = $request->validated();
        $photo = Arr::pull($data, 'photo');
        $removePhoto = (bool) Arr::pull($data, 'remove_photo', false);
        $dateOfBirth = Arr::pull($data, 'date_of_birth');

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $saveProfilePhoto->execute($festivalPortalUser, $photo, $removePhoto, function (FestivalPortalUser $portalUser) use ($account, $data, $dateOfBirth, $syncParticipant): void {
            $this->assertPortalUser($account, $portalUser);

            if ($portalUser->registrantTypeIsLocked()
                && array_key_exists('registrant_type', $data)
                && $portalUser->registrant_type?->value !== $data['registrant_type']) {
                throw ValidationException::withMessages([
                    'registrant_type' => __('app.festival_registrant_type_locked'),
                ]);
            }

            if ($portalUser->role === FestivalPortalRole::Judge && ! $data['is_active'] && $portalUser->judgeAssignments()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['is_active' => __('app.festival_judge_profile_assignment_block')]);
            }

            if ($portalUser->role === FestivalPortalRole::Guest && ! $data['is_active'] && (
                $portalUser->tickets()->where('festival_tickets.status', FestivalTicketStatus::Valid->value)->exists()
                || $portalUser->streamEntitlements()->exists()
            )) {
                throw ValidationException::withMessages(['is_active' => __('app.festival_guest_deactivation_block')]);
            }

            $portalUser->update(Arr::except($data, ['role']));
            $syncParticipant->execute($portalUser, $dateOfBirth);
        });

        return $this->redirectToEdit($account, $festivalEdition, $festivalPortalUser);
    }

    /** @param array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} $permissions */
    private function formView(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser, array $permissions, ?string $returnTo = null): View
    {
        $portalUser->loadMissing('profileParticipant');

        return view('festivals.staff.users.form', [
            'account' => $account,
            'edition' => $edition,
            'portalUser' => $portalUser,
            'returnTo' => $returnTo,
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function permissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);

        return $this->workspaceAccess->permissions($request->user(), $account, $edition);
    }

    /** @param array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} $permissions */
    private function authorizeRole(FestivalPortalRole $role, array $permissions): void
    {
        $allowed = match ($role) {
            FestivalPortalRole::Registrant => $permissions['registrations'],
            FestivalPortalRole::Judge => $permissions['manage'],
            FestivalPortalRole::Guest => $permissions['manage'] || $permissions['finance'],
        };
        abort_unless($allowed, 403);
    }

    private function role(string $role): FestivalPortalRole
    {
        $portalRole = FestivalPortalRole::tryFrom($role);
        abort_unless($portalRole, 404);

        return $portalRole;
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertPortalUser(Account $account, FestivalPortalUser $portalUser): void
    {
        abort_unless($portalUser->account_id === $account->id, 404);
    }

    private function redirectToEdit(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser])
            ->with('status', __('app.festival_user_saved'));
    }
}
