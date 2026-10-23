<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalSponsor;
use App\Http\Requests\FestivalSponsorRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalSponsor;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class FestivalSponsorController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'visibility' => in_array($request->query('visibility'), ['all_pages', 'main_page'], true) ? $request->query('visibility') : '',
        ];
        $sponsors = $festivalEdition->sponsors()
            ->whereBelongsTo($account)
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('name', 'like', '%'.$filters['q'].'%')
                        ->orWhere('short_description', 'like', '%'.$filters['q'].'%');
                });
            })
            ->when($filters['visibility'] !== '', fn ($query) => $query->where('show_on_all_pages', $filters['visibility'] === 'all_pages'))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.sponsors', [
            'account' => $account,
            'edition' => $festivalEdition,
            'sponsors' => $sponsors,
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn (string $value): bool => $value !== ''),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalSponsor, $permissions);
    }

    public function store(FestivalSponsorRequest $request, Account $account, FestivalEdition $festivalEdition, SaveFestivalSponsor $save): RedirectResponse
    {
        $save->execute($account, $festivalEdition, new FestivalSponsor, $request->sponsorPayload(), $request->file('logo'));

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalSponsor $festivalSponsor): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertSponsor($account, $festivalEdition, $festivalSponsor);

        return $this->formView($account, $festivalEdition, $festivalSponsor, $permissions);
    }

    public function update(FestivalSponsorRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalSponsor $festivalSponsor, SaveFestivalSponsor $save): RedirectResponse
    {
        $this->assertSponsor($account, $festivalEdition, $festivalSponsor);
        $save->execute($account, $festivalEdition, $festivalSponsor, $request->sponsorPayload(), $request->file('logo'));

        return $this->redirect($account, $festivalEdition);
    }

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalSponsor $festivalSponsor): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertSponsor($account, $festivalEdition, $festivalSponsor);
        $logoPath = DB::transaction(function () use ($account, $festivalEdition, $festivalSponsor): ?string {
            $sponsor = FestivalSponsor::query()
                ->whereBelongsTo($account)
                ->where('festival_edition_id', $festivalEdition->id)
                ->whereKey($festivalSponsor->id)
                ->lockForUpdate()
                ->firstOrFail();
            $logoPath = $sponsor->logo_path;
            $sponsor->delete();

            return $logoPath;
        }, 3);

        if (is_string($logoPath)) {
            Storage::disk('public')->delete($logoPath);
        }

        return redirect()->route('dashboard.accounts.festivals.settings.sponsors', [$account, $festivalEdition])
            ->with('status', __('app.festival_sponsor_deleted'));
    }

    /** @param array<string, bool> $permissions */
    private function formView(Account $account, FestivalEdition $edition, FestivalSponsor $sponsor, array $permissions): View
    {
        return view('festivals.staff.settings.sponsor-form', [
            'account' => $account,
            'edition' => $edition,
            'sponsor' => $sponsor,
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @return array<string, bool> */
    private function managerPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['manage'], 403);

        return $permissions;
    }

    private function assertSponsor(Account $account, FestivalEdition $edition, FestivalSponsor $sponsor): void
    {
        abort_unless($edition->account_id === $account->id
            && $sponsor->account_id === $account->id
            && $sponsor->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition])
            ->with('status', __('app.festival_sponsor_saved'));
    }
}
