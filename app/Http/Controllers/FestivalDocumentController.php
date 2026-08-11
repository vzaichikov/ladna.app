<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalDocumentRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class FestivalDocumentController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $kinds = ['rules', 'schedule', 'guide', 'document'];
        $visibilities = ['public', 'portal', 'staff'];
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'kind' => in_array($request->query('kind'), $kinds, true) ? $request->query('kind') : '',
            'visibility' => in_array($request->query('visibility'), $visibilities, true) ? $request->query('visibility') : '',
        ];
        $documents = $festivalEdition->documents()
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('title', 'like', '%'.$filters['q'].'%')
                        ->orWhere('original_name', 'like', '%'.$filters['q'].'%');
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['kind'] !== '', fn ($query) => $query->where('kind', $filters['kind']))
            ->when($filters['visibility'] !== '', fn ($query) => $query->where('visibility', $filters['visibility']))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.documents', [
            'account' => $account,
            'edition' => $festivalEdition,
            'documents' => $documents,
            'kinds' => $kinds,
            'visibilities' => $visibilities,
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn ($value) => filled($value)),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalDocument(['is_active' => true]), $permissions);
    }

    public function store(FestivalDocumentRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $file = $request->file('file');
        $path = $file->store("festivals/{$account->id}/editions/{$festivalEdition->id}/documents", 'local');
        $festivalEdition->documents()->create([
            'account_id' => $account->id,
            ...$request->safe()->except(['file', 'is_active']),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $this->settingsOrder->next($festivalEdition->documents()),
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalDocument $festivalDocument): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertDocument($account, $festivalEdition, $festivalDocument);

        return $this->formView($account, $festivalEdition, $festivalDocument, $permissions);
    }

    public function update(FestivalDocumentRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDocument $festivalDocument): RedirectResponse
    {
        $this->assertDocument($account, $festivalEdition, $festivalDocument);
        $data = $request->validated();
        $newPath = $request->file('file')?->store("festivals/{$account->id}/editions/{$festivalEdition->id}/documents", 'local');
        $oldPath = $festivalDocument->path;
        $oldDisk = $festivalDocument->disk;

        try {
            DB::transaction(function () use ($request, $festivalDocument, $data, $newPath): void {
                $values = [
                    ...$request->safe()->except(['file', 'is_active']),
                    'is_active' => $data['is_active'] ?? false,
                ];

                if ($newPath) {
                    $file = $request->file('file');
                    $values = [
                        ...$values,
                        'disk' => 'local',
                        'path' => $newPath,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'size_bytes' => $file->getSize(),
                    ];
                }

                $festivalDocument->update($values);
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath && $oldPath !== $newPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalDocument $festivalDocument): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDocument($account, $festivalEdition, $festivalDocument);
        $festivalDocument->update(['is_active' => ! $festivalDocument->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDocument $festivalDocument): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDocument($account, $festivalEdition, $festivalDocument);
        $this->settingsOrder->move($festivalDocument, $festivalEdition->documents(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalDocument $document, array $permissions): View
    {
        return view('festivals.staff.settings.document-form', [
            'account' => $account,
            'edition' => $edition,
            'document' => $document,
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

    private function assertDocument(Account $account, FestivalEdition $edition, FestivalDocument $document): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($document->account_id === $account->id && $document->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition])
            ->with('status', __('app.festival_document_saved'));
    }
}
