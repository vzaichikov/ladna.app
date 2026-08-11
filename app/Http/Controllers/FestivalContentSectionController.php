<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalContentSectionRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalContentSection;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\StudioRulesHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalContentSectionController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $visibilities = ['public', 'portal', 'staff'];
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'visibility' => in_array($request->query('visibility'), $visibilities, true) ? $request->query('visibility') : '',
        ];
        $sections = $festivalEdition->sections()
            ->when($filters['q'] !== '', fn ($query) => $query->where('title', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['visibility'] !== '', fn ($query) => $query->where('visibility', $filters['visibility']))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.content-sections', [
            'account' => $account,
            'edition' => $festivalEdition,
            'sections' => $sections,
            'visibilities' => $visibilities,
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn ($value) => filled($value)),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalContentSection(['is_active' => true]), $permissions);
    }

    public function store(FestivalContentSectionRequest $request, Account $account, FestivalEdition $festivalEdition, StudioRulesHtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $festivalEdition->sections()->create([
            'account_id' => $account->id,
            ...$data,
            'body_html' => $sanitizer->sanitize($data['body_html'] ?? null),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $this->settingsOrder->next($festivalEdition->sections()),
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalContentSection $festivalContentSection): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertSection($account, $festivalEdition, $festivalContentSection);

        return $this->formView($account, $festivalEdition, $festivalContentSection, $permissions);
    }

    public function update(FestivalContentSectionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalContentSection $festivalContentSection, StudioRulesHtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->assertSection($account, $festivalEdition, $festivalContentSection);
        $data = $request->validated();
        $festivalContentSection->update([
            ...$data,
            'body_html' => $sanitizer->sanitize($data['body_html'] ?? null),
            'is_active' => $data['is_active'] ?? false,
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalContentSection $festivalContentSection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertSection($account, $festivalEdition, $festivalContentSection);
        $festivalContentSection->update(['is_active' => ! $festivalContentSection->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalContentSection $festivalContentSection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertSection($account, $festivalEdition, $festivalContentSection);
        $this->settingsOrder->move($festivalContentSection, $festivalEdition->sections(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalContentSection $section, array $permissions): View
    {
        return view('festivals.staff.settings.content-section-form', [
            'account' => $account,
            'edition' => $edition,
            'section' => $section,
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

    private function assertSection(Account $account, FestivalEdition $edition, FestivalContentSection $section): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($section->account_id === $account->id && $section->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition])
            ->with('status', __('app.festival_content_saved'));
    }
}
