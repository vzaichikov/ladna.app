<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalParticipant;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTeamMemberType;
use App\Http\Requests\StaffFestivalParticipantRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalPortalRosterController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser): View
    {
        $permissions = $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $participants = $festivalPortalUser->participants()
            ->with('portalUser:id,avatar_path')
            ->withCount(['entries', 'helperRequirements'])
            ->orderBy('archived_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        return view('festivals.staff.users.team', [
            'account' => $account,
            'edition' => $festivalEdition,
            'portalUser' => $festivalPortalUser,
            'performers' => $participants->where('member_type', FestivalTeamMemberType::Performer),
            'helpers' => $participants->where('member_type', FestivalTeamMemberType::Helper),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser): View
    {
        $permissions = $this->context($request, $account, $festivalEdition, $festivalPortalUser);

        return $this->formView($account, $festivalEdition, $festivalPortalUser, new FestivalParticipant, $permissions);
    }

    public function store(StaffFestivalParticipantRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, SaveFestivalParticipant $saveParticipant): RedirectResponse
    {
        $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $data = $request->validated();
        $photo = Arr::pull($data, 'photo');
        $saveParticipant->execute($festivalPortalUser, new FestivalParticipant, $data, $photo);

        return $this->redirect($account, $festivalEdition, $festivalPortalUser);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, FestivalParticipant $festivalParticipant): View
    {
        $permissions = $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $this->assertParticipant($festivalPortalUser, $festivalParticipant);
        abort_if($festivalParticipant->is_profile_owner, 409);

        return $this->formView($account, $festivalEdition, $festivalPortalUser, $festivalParticipant, $permissions);
    }

    public function update(StaffFestivalParticipantRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, FestivalParticipant $festivalParticipant, SaveFestivalParticipant $saveParticipant): RedirectResponse
    {
        $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $this->assertParticipant($festivalPortalUser, $festivalParticipant);
        abort_if($festivalParticipant->is_profile_owner, 409);
        $data = $request->validated();
        $photo = Arr::pull($data, 'photo');
        $saveParticipant->execute($festivalPortalUser, $festivalParticipant, $data, $photo);

        return $this->redirect($account, $festivalEdition, $festivalPortalUser);
    }

    public function archive(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, FestivalParticipant $festivalParticipant): View
    {
        $permissions = $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $this->assertParticipant($festivalPortalUser, $festivalParticipant);
        abort_if($festivalParticipant->is_profile_owner, 409);
        $festivalParticipant->loadCount(['entries', 'helperRequirements']);

        return view('festivals.staff.users.participant-archive', [
            'account' => $account,
            'edition' => $festivalEdition,
            'portalUser' => $festivalPortalUser,
            'participant' => $festivalParticipant,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, FestivalParticipant $festivalParticipant): RedirectResponse
    {
        $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $this->assertParticipant($festivalPortalUser, $festivalParticipant);
        DB::transaction(function () use ($festivalPortalUser, $festivalParticipant): void {
            $participant = FestivalParticipant::query()
                ->whereKey($festivalParticipant->id)
                ->where('account_id', $festivalPortalUser->account_id)
                ->where('festival_portal_user_id', $festivalPortalUser->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($participant->is_profile_owner, 409);
            abort_if($participant->isInUse(), 409, __('app.festival_participant_archive_block'));
            $participant->forceFill(['archived_at' => now()])->save();
        }, 3);

        return $this->redirect($account, $festivalEdition, $festivalPortalUser, __('app.festival_participant_archived'));
    }

    /** @param array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} $permissions */
    private function formView(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser, FestivalParticipant $participant, array $permissions): View
    {
        if ($participant->exists) {
            $participant->loadCount(['entries', 'helperRequirements']);
        }

        return view('festivals.staff.users.participant-form', [
            'account' => $account,
            'edition' => $edition,
            'portalUser' => $portalUser,
            'participant' => $participant,
            'memberTypeLocked' => $participant->exists && (($participant->entries_count ?? 0) > 0 || ($participant->helper_requirements_count ?? 0) > 0),
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function context(Request $request, Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser): array
    {
        $this->assertContext($account, $edition, $portalUser);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['registrations'], 403);

        return $permissions;
    }

    private function assertContext(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser): void
    {
        abort_unless(
            $edition->account_id === $account->id
            && $portalUser->account_id === $account->id
            && $portalUser->role === FestivalPortalRole::Registrant,
            404,
        );
    }

    private function assertParticipant(FestivalPortalUser $portalUser, FestivalParticipant $participant): void
    {
        abort_unless(
            $participant->account_id === $portalUser->account_id
            && $participant->festival_portal_user_id === $portalUser->id,
            404,
        );
    }

    private function redirect(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser, ?string $status = null): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser])
            ->with('status', $status ?? __('app.festival_participant_saved'));
    }
}
