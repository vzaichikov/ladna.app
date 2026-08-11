<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalMediaRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalMedia;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalMediaController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $kinds = ['image', 'video'];
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'kind' => in_array($request->query('kind'), $kinds, true) ? $request->query('kind') : '',
            'cover' => in_array($request->query('cover'), ['cover', 'regular'], true) ? $request->query('cover') : '',
        ];
        $mediaItems = $festivalEdition->media()
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('caption', 'like', '%'.$filters['q'].'%')
                        ->orWhere('alt_text', 'like', '%'.$filters['q'].'%')
                        ->orWhere('external_url', 'like', '%'.$filters['q'].'%');
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['kind'] !== '', fn ($query) => $query->where('kind', $filters['kind']))
            ->when($filters['cover'] !== '', fn ($query) => $query->where('is_cover', $filters['cover'] === 'cover'))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.media', [
            'account' => $account,
            'edition' => $festivalEdition,
            'mediaItems' => $mediaItems,
            'kinds' => $kinds,
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn ($value) => filled($value)),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalMedia(['is_active' => true]), $permissions);
    }

    public function store(FestivalMediaRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $this->clearCover($festivalEdition, (bool) ($data['is_cover'] ?? false));
        $festivalEdition->media()->create([
            'account_id' => $account->id,
            ...$data,
            'is_cover' => $data['is_cover'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $this->settingsOrder->next($festivalEdition->media()),
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalMedia $festivalMedia): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertMedia($account, $festivalEdition, $festivalMedia);

        return $this->formView($account, $festivalEdition, $festivalMedia, $permissions);
    }

    public function update(FestivalMediaRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalMedia $festivalMedia): RedirectResponse
    {
        $this->assertMedia($account, $festivalEdition, $festivalMedia);
        $data = $request->validated();
        $this->clearCover($festivalEdition, (bool) ($data['is_cover'] ?? false), $festivalMedia);
        $festivalMedia->update([
            ...$data,
            'is_cover' => $data['is_cover'] ?? false,
            'is_active' => $data['is_active'] ?? false,
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalMedia $festivalMedia): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertMedia($account, $festivalEdition, $festivalMedia);
        $festivalMedia->update(['is_active' => ! $festivalMedia->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalMedia $festivalMedia): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertMedia($account, $festivalEdition, $festivalMedia);
        $this->settingsOrder->move($festivalMedia, $festivalEdition->media(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    private function clearCover(FestivalEdition $edition, bool $makeCover, ?FestivalMedia $except = null): void
    {
        if (! $makeCover) {
            return;
        }

        $edition->media()
            ->when($except, fn ($query) => $query->where('id', '!=', $except->id))
            ->update(['is_cover' => false]);
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalMedia $media, array $permissions): View
    {
        return view('festivals.staff.settings.media-form', [
            'account' => $account,
            'edition' => $edition,
            'media' => $media,
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function managerPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['manage'], 403);

        return $permissions;
    }

    private function authorizeManager(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertMedia(Account $account, FestivalEdition $edition, FestivalMedia $media): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($media->account_id === $account->id && $media->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.content.media', [$account, $edition])
            ->with('status', __('app.festival_media_saved'));
    }
}
