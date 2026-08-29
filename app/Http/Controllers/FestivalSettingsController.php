<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalRequirementDefinition;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalSettingsController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function overview(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['manage'] || $permissions['schedule'] || $permissions['finance'], 403);

        $counts = [
            'directions' => $permissions['manage'] ? $festivalEdition->directions()->count() : null,
            'nominations' => $permissions['manage'] ? $festivalEdition->nominations()->count() : null,
            'stages' => $permissions['schedule'] ? $festivalEdition->stages()->count() : null,
            'categories' => $permissions['manage'] ? $festivalEdition->categories()->count() : null,
            'workflows' => $permissions['manage'] ? $festivalEdition->workflows()->count() : null,
            'requirements' => $permissions['manage'] ? FestivalRequirementDefinition::query()->where('festival_edition_id', $festivalEdition->id)->count() : null,
            'fees' => $permissions['finance'] ? FestivalChargeDefinition::query()->where('festival_edition_id', $festivalEdition->id)->count() : null,
            'content' => $permissions['manage'] ? $festivalEdition->sections()->count() + $festivalEdition->documents()->count() + $festivalEdition->media()->count() : null,
        ];

        return view('festivals.staff.settings.overview', compact('account', 'festivalEdition', 'permissions', 'counts') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function content(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $counts = [
            'sections' => $festivalEdition->sections()->count(),
            'documents' => $festivalEdition->documents()->count(),
            'media' => $festivalEdition->media()->count(),
        ];

        return view('festivals.staff.settings.content', compact('account', 'permissions', 'counts') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function managerPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $permissions = $this->permissions($request, $account, $edition);
        abort_unless($permissions['manage'], 403);

        return $permissions;
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function permissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);

        return $this->workspaceAccess->permissions($request->user(), $account, $edition);
    }
}
