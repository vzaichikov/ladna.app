<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ActivateFestivalTimelineItem;
use App\Actions\Festivals\FillFestivalTimelines;
use App\Actions\Festivals\PauseFestivalTimeline;
use App\Actions\Festivals\ReorderFestivalTimeline;
use App\Actions\Festivals\ResumeFestivalTimeline;
use App\Actions\Festivals\StartFestivalTimelines;
use App\Actions\Festivals\ToggleFestivalTimelineItem;
use App\Http\Requests\FestivalTimelineOrderRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalStage;
use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use App\Support\Festivals\FestivalTimelinePresenter;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FestivalTimelineController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalTimelinePresenter $presenter,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        $stage = $festivalEdition->stages()->where('is_active', true)->first();

        if (! $stage) {
            return redirect()->route('dashboard.accounts.festivals.settings.stages', [$account, $festivalEdition])
                ->withErrors(['timeline' => __('app.festival_timeline_no_active_scenes')]);
        }

        return redirect()->route('dashboard.accounts.festivals.timeline.show', [$account, $festivalEdition, $stage]);
    }

    public function show(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage): View
    {
        $permissions = $this->authorizeSchedule($request, $account, $festivalEdition);
        $this->assertStage($account, $festivalEdition, $festivalStage);

        return view('festivals.staff.timeline', [
            'account' => $account,
            'edition' => $festivalEdition,
            'stage' => $festivalStage,
            'workspacePermissions' => $permissions,
            ...$this->staffFragmentData($festivalEdition, $festivalStage),
        ]);
    }

    public function fragment(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage): Response
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        $this->assertStage($account, $festivalEdition, $festivalStage);

        return response()
            ->view('festivals.staff._timeline-fragment', $this->staffFragmentData($festivalEdition, $festivalStage))
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function fill(Request $request, Account $account, FestivalEdition $festivalEdition, FillFestivalTimelines $fill): RedirectResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        $fill->execute($festivalEdition, $this->actor($request));

        return redirect()->route('dashboard.accounts.festivals.timeline.index', [$account, $festivalEdition])
            ->with('status', __('app.festival_timeline_filled'));
    }

    public function start(Request $request, Account $account, FestivalEdition $festivalEdition, StartFestivalTimelines $start): RedirectResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        $start->execute($festivalEdition, $this->actor($request));

        return redirect()->route('dashboard.accounts.festivals.timeline.index', [$account, $festivalEdition])
            ->with('status', __('app.festival_timeline_started'));
    }

    public function pause(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage, PauseFestivalTimeline $pause): JsonResponse|RedirectResponse
    {
        $timeline = $this->timeline($request, $account, $festivalEdition, $festivalStage);
        $pause->execute($timeline, $this->actor($request));

        return $this->actionResponse($request, $festivalEdition, $festivalStage, __('app.festival_timeline_paused'));
    }

    public function resume(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage, ResumeFestivalTimeline $resume): JsonResponse|RedirectResponse
    {
        $timeline = $this->timeline($request, $account, $festivalEdition, $festivalStage);
        $resume->execute($timeline, $this->actor($request));

        return $this->actionResponse($request, $festivalEdition, $festivalStage, __('app.festival_timeline_resumed'));
    }

    public function reorder(FestivalTimelineOrderRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage, ReorderFestivalTimeline $reorder): JsonResponse
    {
        $timeline = $this->timeline($request, $account, $festivalEdition, $festivalStage);
        $sortOrders = $reorder->execute($timeline, $request->validated('items'), $this->actor($request));

        return response()->json(['sort_orders' => $sortOrders, 'message' => __('app.festival_timeline_order_saved')]);
    }

    public function activate(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage, FestivalTimelineItem $festivalTimelineItem, ActivateFestivalTimelineItem $activate): JsonResponse|RedirectResponse
    {
        $timeline = $this->timeline($request, $account, $festivalEdition, $festivalStage);
        $this->assertItem($timeline, $festivalTimelineItem);
        $activate->execute($timeline, $festivalTimelineItem, $this->actor($request));

        return $this->actionResponse($request, $festivalEdition, $festivalStage, __('app.festival_timeline_item_started'));
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage, FestivalTimelineItem $festivalTimelineItem, ToggleFestivalTimelineItem $toggle): JsonResponse|RedirectResponse
    {
        $timeline = $this->timeline($request, $account, $festivalEdition, $festivalStage);
        $this->assertItem($timeline, $festivalTimelineItem);
        $item = $toggle->execute($timeline, $festivalTimelineItem, $this->actor($request));

        $message = $item->is_enabled ? __('app.festival_timeline_item_enabled') : __('app.festival_timeline_item_disabled_status');

        return $this->actionResponse($request, $festivalEdition, $festivalStage, $message);
    }

    public function publicFragment(Request $request, string $accountSlug, string $editionSlug): Response
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)->firstOrFail();
        $data = $this->publicFragmentData($edition, $account);

        return response()
            ->view('festivals.public._timeline', $data)
            ->header('Cache-Control', 'public, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /** @return array<string, mixed> */
    public function publicFragmentData(FestivalEdition $edition, Account $account): array
    {
        $withinDates = $this->presenter->isWithinLocalDates($edition);
        $timelines = $withinDates
            ? FestivalTimeline::query()
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $account->id)
                ->whereNotNull('started_at')
                ->whereHas('stage', fn ($query) => $query->where('is_active', true))
                ->with(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem'])
                ->get()
            : collect();

        return [
            'edition' => $edition,
            'publicTimelineViews' => $this->presenter->scenes($timelines, true),
            'timelineWithinDates' => $withinDates,
            'timelinePollingUrl' => route('public.festivals.timeline', [$account->slug, $edition->slug]),
        ];
    }

    /** @return array<string, mixed> */
    private function staffFragmentData(FestivalEdition $edition, FestivalStage $stage): array
    {
        $timeline = FestivalTimeline::query()
            ->where('festival_edition_id', $edition->id)
            ->where('festival_stage_id', $stage->id)
            ->with(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem'])
            ->first();

        return [
            'account' => $edition->account,
            'edition' => $edition,
            'stage' => $stage,
            'timeline' => $timeline,
            'timelineView' => $timeline ? $this->presenter->scene($timeline) : null,
            'timelineFragmentUrl' => route('dashboard.accounts.festivals.timeline.fragment', [$edition->account_id, $edition, $stage]),
        ];
    }

    /** @return array<string, bool> */
    private function authorizeSchedule(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['schedule'], 403);

        return $permissions;
    }

    private function timeline(Request $request, Account $account, FestivalEdition $edition, FestivalStage $stage): FestivalTimeline
    {
        $this->authorizeSchedule($request, $account, $edition);
        $this->assertStage($account, $edition, $stage);

        return FestivalTimeline::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->where('festival_stage_id', $stage->id)
            ->firstOrFail();
    }

    private function assertStage(Account $account, FestivalEdition $edition, FestivalStage $stage): void
    {
        abort_unless($stage->account_id === $account->id && $stage->festival_edition_id === $edition->id, 404);
    }

    private function assertItem(FestivalTimeline $timeline, FestivalTimelineItem $item): void
    {
        abort_unless($item->account_id === $timeline->account_id
            && $item->festival_edition_id === $timeline->festival_edition_id
            && $item->festival_timeline_id === $timeline->id, 404);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function actionResponse(Request $request, FestivalEdition $edition, FestivalStage $stage, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'fragment_html' => view('festivals.staff._timeline-fragment', $this->staffFragmentData($edition, $stage))->render(),
            ])->header('Cache-Control', 'private, no-store, max-age=0');
        }

        return back()->with('status', $message);
    }
}
