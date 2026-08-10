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
        abort_unless($permissions['manage'] || $permissions['finance'], 403);

        $counts = [
            'directions' => $permissions['manage'] ? $festivalEdition->axes()->where('kind', 'direction')->withCount('options')->get()->sum('options_count') : null,
            'classifications' => $permissions['manage'] ? $festivalEdition->axes()->where('kind', '!=', 'direction')->count() : null,
            'categories' => $permissions['manage'] ? $festivalEdition->categories()->count() : null,
            'workflows' => $permissions['manage'] ? $festivalEdition->workflows()->count() : null,
            'requirements' => $permissions['manage'] ? FestivalRequirementDefinition::query()->where('festival_edition_id', $festivalEdition->id)->count() : null,
            'fees' => $permissions['finance'] ? FestivalChargeDefinition::query()->where('festival_edition_id', $festivalEdition->id)->count() : null,
            'content' => $permissions['manage'] ? $festivalEdition->sections()->count() + $festivalEdition->documents()->count() + $festivalEdition->media()->count() : null,
        ];

        return view('festivals.staff.settings.overview', compact('account', 'festivalEdition', 'permissions', 'counts') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function directions(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $axes = $festivalEdition->axes()->where('kind', 'direction')->with(['options' => fn ($query) => $query->withCount('categories')])->get();

        return view('festivals.staff.settings.directions', compact('account', 'axes', 'permissions') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function classifications(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $axes = $festivalEdition->axes()->where('kind', '!=', 'direction')->with(['options' => fn ($query) => $query->withCount('categories')])->get();

        return view('festivals.staff.settings.classifications', compact('account', 'axes', 'permissions') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function categories(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $festivalEdition->load([
            'categories' => fn ($query) => $query->with(['options.axis', 'registrationWorkflow'])->withCount('entries'),
            'axes.options',
            'workflows',
        ]);

        return view('festivals.staff.settings.categories', compact('account', 'permissions') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function workflows(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $festivalEdition->load(['workflows' => fn ($query) => $query->with(['steps' => fn ($steps) => $steps->withCount(['requirementDefinitions', 'chargeDefinitions'])])->withCount('categories')]);

        return view('festivals.staff.settings.workflows', compact('account', 'permissions') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function requirements(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $festivalEdition->load(['categories', 'workflows.steps']);
        $requirements = FestivalRequirementDefinition::query()->where('festival_edition_id', $festivalEdition->id)->with(['category', 'workflowStep.workflow'])->orderBy('sort_order')->orderBy('id')->get();

        return view('festivals.staff.settings.requirements', compact('account', 'requirements', 'permissions') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function fees(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->permissions($request, $account, $festivalEdition);
        abort_unless($permissions['finance'], 403);
        $festivalEdition->load(['categories', 'workflows.steps']);
        $fees = FestivalChargeDefinition::query()->where('festival_edition_id', $festivalEdition->id)->with(['category', 'workflowStep.workflow'])->orderBy('sort_order')->orderBy('id')->get();

        return view('festivals.staff.settings.fees', compact('account', 'fees', 'permissions') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
    }

    public function content(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $festivalEdition->load(['sections', 'documents', 'media']);

        return view('festivals.staff.settings.content', compact('account', 'permissions') + ['edition' => $festivalEdition, 'workspacePermissions' => $permissions]);
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
