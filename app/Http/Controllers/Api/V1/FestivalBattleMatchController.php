<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Festivals\SubmitFestivalBattleAudienceScore;
use App\Enums\FestivalBattleMatchStatus;
use App\Enums\FestivalEditionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFestivalBattleAudienceScoreRequest;
use App\Http\Resources\Api\V1\FestivalBattleMatchResource;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\FestivalBattleMatch;
use App\Support\Festivals\FestivalBattleMatchSnapshot;
use App\Support\Festivals\FestivalSaasAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FestivalBattleMatchController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);
        $matches = FestivalBattleMatch::query()
            ->where('festival_battle_matches.account_id', $account->id)
            ->where('festival_battle_matches.status', FestivalBattleMatchStatus::Ready->value)
            ->whereNotNull('entry_a_id')
            ->whereNotNull('entry_b_id')
            ->whereHas('edition', fn ($query) => $query->whereIn('status', [
                FestivalEditionStatus::Published->value,
                FestivalEditionStatus::InProgress->value,
            ]))
            ->with($this->relations())
            ->join('festival_editions', 'festival_editions.id', '=', 'festival_battle_matches.festival_edition_id')
            ->orderByDesc('festival_editions.starts_at')
            ->orderBy('festival_battle_matches.festival_category_id')
            ->orderBy('festival_battle_matches.round')
            ->orderBy('festival_battle_matches.position')
            ->select('festival_battle_matches.*')
            ->get();

        return FestivalBattleMatchResource::collection($matches)
            ->additional(['meta' => $this->meta($account)])
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $match): Response
    {
        $account = $this->account($request);
        $battleMatch = $this->match($account, $match);

        return FestivalBattleMatchResource::make($battleMatch)
            ->additional(['meta' => $this->meta($account)])
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    public function updateAudienceScore(
        StoreFestivalBattleAudienceScoreRequest $request,
        int $match,
        SubmitFestivalBattleAudienceScore $submit,
        FestivalSaasAccess $saasAccess,
    ): Response {
        $account = $this->account($request);
        $battleMatch = $this->match($account, $match);
        $saasAccess->assertEditionWritable($battleMatch->edition);
        $token = $request->attributes->get('accountApiToken');
        abort_unless($token instanceof AccountApiToken, 401);
        $data = $request->validated();
        $result = $submit->execute(
            $battleMatch,
            (int) $data['audience_score_a'],
            (int) $data['audience_score_b'],
            $token,
            $data,
        );

        if ($result['conflict']) {
            return new JsonResponse([
                'message' => __('app.festival_battle_api_score_conflict'),
                'code' => 'audience_score_conflict',
                'data' => app(FestivalBattleMatchSnapshot::class)->for($result['match']),
            ], Response::HTTP_CONFLICT, ['Cache-Control' => 'no-store']);
        }

        $status = $result['match']->status === FestivalBattleMatchStatus::Completed
            ? Response::HTTP_OK
            : Response::HTTP_ACCEPTED;

        return FestivalBattleMatchResource::make($result['match'])
            ->additional(['meta' => $this->meta($account)])
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store');
    }

    private function account(Request $request): Account
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account, 404);

        return $account;
    }

    private function match(Account $account, int $matchId): FestivalBattleMatch
    {
        return FestivalBattleMatch::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [FestivalBattleMatchStatus::Ready->value, FestivalBattleMatchStatus::Completed->value])
            ->with($this->relations())
            ->findOrFail($matchId);
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'edition:id,title,status',
            'category:id,name',
            'entryA:id,entry_name',
            'entryB:id,entry_name',
            'winner:id,entry_name',
        ];
    }

    /** @return array{locale: string, poll_interval_seconds: int} */
    private function meta(Account $account): array
    {
        return [
            'locale' => in_array($account->default_language, ['en', 'uk'], true) ? $account->default_language : 'en',
            'poll_interval_seconds' => 5,
        ];
    }
}
