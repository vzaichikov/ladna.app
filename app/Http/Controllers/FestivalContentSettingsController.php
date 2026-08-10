<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalContentSectionRequest;
use App\Http\Requests\FestivalDocumentRequest;
use App\Http\Requests\FestivalMediaRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalContentSection;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Models\FestivalMedia;
use App\Support\StudioRulesHtmlSanitizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FestivalContentSettingsController extends Controller
{
    public function storeSection(FestivalContentSectionRequest $request, Account $account, FestivalEdition $festivalEdition, StudioRulesHtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $festivalEdition->sections()->create(['account_id' => $account->id, ...$data, 'body_html' => $sanitizer->sanitize($data['body_html'] ?? null), 'is_active' => $data['is_active'] ?? true, 'sort_order' => $this->nextSort($festivalEdition->sections())]);

        return $this->redirect($account, $festivalEdition, __('app.festival_content_saved'));
    }

    public function updateSection(FestivalContentSectionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalContentSection $festivalContentSection, StudioRulesHtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->assertSection($account, $festivalEdition, $festivalContentSection);
        $data = $request->validated();
        $festivalContentSection->update([...$data, 'body_html' => $sanitizer->sanitize($data['body_html'] ?? null), 'is_active' => $data['is_active'] ?? false]);

        return $this->redirect($account, $festivalEdition, __('app.festival_content_saved'));
    }

    public function toggleSection(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalContentSection $festivalContentSection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertSection($account, $festivalEdition, $festivalContentSection);
        $festivalContentSection->update(['is_active' => ! $festivalContentSection->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveSection(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalContentSection $festivalContentSection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertSection($account, $festivalEdition, $festivalContentSection);
        $this->move($festivalContentSection, $festivalEdition->sections()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function storeDocument(FestivalDocumentRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
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
            'sort_order' => $this->nextSort($festivalEdition->documents()),
        ]);

        return $this->redirect($account, $festivalEdition, __('app.festival_document_saved'));
    }

    public function updateDocument(FestivalDocumentRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDocument $festivalDocument): RedirectResponse
    {
        $this->assertDocument($account, $festivalEdition, $festivalDocument);
        $data = $request->validated();
        $newPath = $request->file('file')?->store("festivals/{$account->id}/editions/{$festivalEdition->id}/documents", 'local');
        $oldPath = $festivalDocument->path;

        try {
            DB::transaction(function () use ($request, $festivalDocument, $data, $newPath): void {
                $values = [...$request->safe()->except(['file', 'is_active']), 'is_active' => $data['is_active'] ?? false];
                if ($newPath) {
                    $file = $request->file('file');
                    $values = [...$values, 'disk' => 'local', 'path' => $newPath, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize()];
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
            Storage::disk($festivalDocument->disk)->delete($oldPath);
        }

        return $this->redirect($account, $festivalEdition, __('app.festival_document_saved'));
    }

    public function toggleDocument(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalDocument $festivalDocument): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDocument($account, $festivalEdition, $festivalDocument);
        $festivalDocument->update(['is_active' => ! $festivalDocument->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveDocument(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDocument $festivalDocument): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDocument($account, $festivalEdition, $festivalDocument);
        $this->move($festivalDocument, $festivalEdition->documents()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function storeMedia(FestivalMediaRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $this->clearCover($festivalEdition, (bool) ($data['is_cover'] ?? false));
        $festivalEdition->media()->create(['account_id' => $account->id, ...$data, 'is_cover' => $data['is_cover'] ?? false, 'is_active' => $data['is_active'] ?? true, 'sort_order' => $this->nextSort($festivalEdition->media())]);

        return $this->redirect($account, $festivalEdition, __('app.festival_media_saved'));
    }

    public function updateMedia(FestivalMediaRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalMedia $festivalMedia): RedirectResponse
    {
        $this->assertMedia($account, $festivalEdition, $festivalMedia);
        $data = $request->validated();
        $this->clearCover($festivalEdition, (bool) ($data['is_cover'] ?? false), $festivalMedia);
        $festivalMedia->update([...$data, 'is_cover' => $data['is_cover'] ?? false, 'is_active' => $data['is_active'] ?? false]);

        return $this->redirect($account, $festivalEdition, __('app.festival_media_saved'));
    }

    public function toggleMedia(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalMedia $festivalMedia): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertMedia($account, $festivalEdition, $festivalMedia);
        $festivalMedia->update(['is_active' => ! $festivalMedia->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveMedia(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalMedia $festivalMedia): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertMedia($account, $festivalEdition, $festivalMedia);
        $this->move($festivalMedia, $festivalEdition->media()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    private function clearCover(FestivalEdition $edition, bool $makeCover, ?FestivalMedia $except = null): void
    {
        if (! $makeCover) {
            return;
        }
        $edition->media()->when($except, fn ($query) => $query->where('id', '!=', $except->id))->update(['is_cover' => false]);
    }

    /** @param Collection<int, Model> $items */
    private function move(Model $model, Collection $items, string $direction): void
    {
        DB::transaction(function () use ($model, $items, $direction): void {
            $items = $items->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
            foreach ($items as $index => $item) {
                $item->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }
            $index = $items->search(fn (Model $item): bool => $item->is($model));
            if ($index === false) {
                return;
            }
            $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if (! $items->has($targetIndex)) {
                return;
            }
            $target = $items[$targetIndex];
            $currentOrder = $items[$index]->sort_order;
            $items[$index]->update(['sort_order' => $target->sort_order]);
            $target->update(['sort_order' => $currentOrder]);
        });
    }

    private function nextSort($relation): int
    {
        return ((int) $relation->max('sort_order')) + 10;
    }

    private function redirect(Account $account, FestivalEdition $edition, string $message): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.content', [$account, $edition])->with('status', $message);
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

    private function assertDocument(Account $account, FestivalEdition $edition, FestivalDocument $document): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($document->account_id === $account->id && $document->festival_edition_id === $edition->id, 404);
    }

    private function assertMedia(Account $account, FestivalEdition $edition, FestivalMedia $media): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($media->account_id === $account->id && $media->festival_edition_id === $edition->id, 404);
    }
}
