<?php

namespace App\Http\Controllers;

use App\Enums\FestivalPortalRole;
use App\Http\Requests\StaffFestivalParticipantRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalPortalRosterController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser): View
    {
        $permissions = $this->context($request, $account, $festivalEdition, $festivalPortalUser);

        return $this->formView($account, $festivalEdition, $festivalPortalUser, new FestivalParticipant, $permissions);
    }

    public function store(StaffFestivalParticipantRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser): RedirectResponse
    {
        $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $festivalPortalUser->participants()->create([
            'account_id' => $account->id,
            ...$request->validated(),
        ]);

        return $this->redirect($account, $festivalEdition, $festivalPortalUser);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, FestivalParticipant $festivalParticipant): View
    {
        $permissions = $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $this->assertParticipant($festivalPortalUser, $festivalParticipant);
        abort_if($festivalParticipant->is_profile_owner, 409);

        return $this->formView($account, $festivalEdition, $festivalPortalUser, $festivalParticipant, $permissions);
    }

    public function update(StaffFestivalParticipantRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, FestivalParticipant $festivalParticipant): RedirectResponse
    {
        $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $this->assertParticipant($festivalPortalUser, $festivalParticipant);
        abort_if($festivalParticipant->is_profile_owner, 409);
        $festivalParticipant->update($request->validated());

        return $this->redirect($account, $festivalEdition, $festivalPortalUser);
    }

    public function archive(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPortalUser $festivalPortalUser, FestivalParticipant $festivalParticipant): View
    {
        $permissions = $this->context($request, $account, $festivalEdition, $festivalPortalUser);
        $this->assertParticipant($festivalPortalUser, $festivalParticipant);
        abort_if($festivalParticipant->is_profile_owner, 409);
        $festivalParticipant->loadCount('entries');

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
        abort_if($festivalParticipant->is_profile_owner, 409);
        abort_if($festivalParticipant->entries()->where('status', '!=', 'draft')->exists(), 409, __('app.festival_participant_archive_block'));
        $festivalParticipant->forceFill(['archived_at' => now()])->save();

        return $this->redirect($account, $festivalEdition, $festivalPortalUser, __('app.festival_participant_archived'));
    }

    /** @param array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} $permissions */
    private function formView(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser, FestivalParticipant $participant, array $permissions): View
    {
        return view('festivals.staff.users.participant-form', [
            'account' => $account,
            'edition' => $edition,
            'portalUser' => $portalUser,
            'participant' => $participant,
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
        return redirect()->route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser])
            ->with('status', $status ?? __('app.festival_participant_saved'));
    }
}
