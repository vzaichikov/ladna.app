<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalParticipant;
use App\Enums\FestivalTeamMemberType;
use App\Http\Requests\FestivalParticipantRequest;
use App\Models\Account;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalParticipantController extends Controller
{
    public function index(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $participants = $this->participants($portalUser);
        $editParticipantId = (int) old('team_participant_id', $request->integer('edit'));
        $editParticipant = $participants->first(
            fn (FestivalParticipant $participant): bool => $participant->id === $editParticipantId
                && ! $participant->is_profile_owner,
        );
        $addMemberType = FestivalTeamMemberType::tryFrom((string) $request->query('add'));

        return view('festivals.portal.participants', compact(
            'account',
            'portalUser',
            'participants',
            'editParticipant',
            'addMemberType',
        ));
    }

    public function store(
        FestivalParticipantRequest $request,
        string $accountSlug,
        SaveFestivalParticipant $saveParticipant,
    ): JsonResponse|RedirectResponse {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $photo = $request->file('photo');
        $participant = $saveParticipant->execute(
            $portalUser,
            new FestivalParticipant,
            $request->validated(),
            $photo instanceof UploadedFile ? $photo : null,
        );

        return $this->savedResponse($request, $account, $portalUser, $participant);
    }

    public function update(
        FestivalParticipantRequest $request,
        string $accountSlug,
        FestivalParticipant $festivalParticipant,
        SaveFestivalParticipant $saveParticipant,
    ): JsonResponse|RedirectResponse {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($festivalParticipant->festival_portal_user_id === $portalUser->id && $festivalParticipant->account_id === $portalUser->account_id, 404);
        abort_if($festivalParticipant->is_profile_owner, 409);
        $photo = $request->file('photo');
        $participant = $saveParticipant->execute(
            $portalUser,
            $festivalParticipant,
            $request->validated(),
            $photo instanceof UploadedFile ? $photo : null,
        );

        return $this->savedResponse($request, $account, $portalUser, $participant);
    }

    public function destroy(Request $request, string $accountSlug, FestivalParticipant $festivalParticipant): JsonResponse|RedirectResponse
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($festivalParticipant->festival_portal_user_id === $portalUser->id && $festivalParticipant->account_id === $portalUser->account_id, 404);
        DB::transaction(function () use ($portalUser, $festivalParticipant): void {
            $participant = FestivalParticipant::query()
                ->whereKey($festivalParticipant->id)
                ->where('account_id', $portalUser->account_id)
                ->where('festival_portal_user_id', $portalUser->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($participant->is_profile_owner, 409);
            abort_if($participant->isInUse(), 409, __('app.festival_participant_archive_block'));
            $participant->forceFill(['archived_at' => now()])->save();
        }, 3);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('app.festival_portal_removed_from_team'),
                'team_html' => $this->teamHtml($account, $portalUser),
            ]);
        }

        return redirect()
            ->route('festival.portal.participants.index', $account->slug)
            ->with('status', __('app.festival_portal_removed_from_team'));
    }

    private function savedResponse(
        FestivalParticipantRequest $request,
        Account $account,
        FestivalPortalUser $portalUser,
        FestivalParticipant $participant,
    ): JsonResponse|RedirectResponse {
        $message = __('app.festival_portal_team_saved');

        if (! $request->expectsJson()) {
            return redirect()
                ->route('festival.portal.participants.index', $account->slug)
                ->with('status', $message);
        }

        return response()->json([
            'message' => $message,
            'resource_id' => $participant->id,
            'team_html' => $this->teamHtml($account, $portalUser),
            'helper_option_html' => $request->validated('fragment_context') === 'helper_selection'
                && $participant->member_type === FestivalTeamMemberType::Helper
                    ? view('festivals.portal.team._helper-option', [
                        'account' => $account,
                        'participant' => $participant,
                        'selected' => true,
                    ])->render()
                    : null,
            'performer_option_html' => $request->validated('fragment_context') === 'performer_selection'
                && $participant->member_type === FestivalTeamMemberType::Performer
                    ? view('festivals.portal.team._performer-option', [
                        'account' => $account,
                        'participant' => $participant,
                        'selected' => true,
                    ])->render()
                    : null,
        ]);
    }

    private function teamHtml(Account $account, FestivalPortalUser $portalUser): string
    {
        return view('festivals.portal.team._list', [
            'account' => $account,
            'participants' => $this->participants($portalUser),
        ])->render();
    }

    /** @return Collection<int, FestivalParticipant> */
    private function participants(FestivalPortalUser $portalUser): Collection
    {
        return $portalUser->participants()
            ->active()
            ->with('portalUser')
            ->withCount(['entries', 'helperRequirements'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
    }

    /** @return array{Account, FestivalPortalUser} */
    private function context(Request $request, string $slug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $slug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id, 404);

        return [$account, $portalUser];
    }
}
