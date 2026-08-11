<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalDirectionRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FestivalDirectionController extends Controller
{
    public function store(FestivalDirectionRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $festivalEdition->directions()->create([
            'account_id' => $account->id,
            ...$data,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => ((int) $festivalEdition->directions()->max('sort_order')) + 10,
        ]);

        return back()->with('status', __('app.festival_direction_saved'));
    }

    public function update(FestivalDirectionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDirection $festivalDirection): RedirectResponse
    {
        $this->assertDirection($account, $festivalEdition, $festivalDirection);
        $data = $request->validated();
        DB::transaction(function () use ($account, $festivalEdition, $festivalDirection, $data): void {
            $direction = FestivalDirection::query()->whereKey($festivalDirection->id)->lockForUpdate()->firstOrFail();
            $this->assertDirection($account, $festivalEdition, $direction);
            $isActive = $data['is_active'] ?? $direction->is_active;
            $this->assertCanDeactivate($direction, $isActive);
            $direction->update([...$data, 'is_active' => $isActive]);
        }, 3);

        return back()->with('status', __('app.festival_direction_saved'));
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalDirection $festivalDirection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDirection($account, $festivalEdition, $festivalDirection);
        DB::transaction(function () use ($account, $festivalEdition, $festivalDirection): void {
            $direction = FestivalDirection::query()->whereKey($festivalDirection->id)->lockForUpdate()->firstOrFail();
            $this->assertDirection($account, $festivalEdition, $direction);
            $isActive = ! $direction->is_active;
            $this->assertCanDeactivate($direction, $isActive);
            $direction->update(['is_active' => $isActive]);
        }, 3);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDirection $festivalDirection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDirection($account, $festivalEdition, $festivalDirection);
        $this->moveWithin($festivalDirection, $festivalEdition->directions()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    private function assertCanDeactivate(FestivalDirection $direction, bool $isActive): void
    {
        if (! $isActive && $direction->categories()->exists()) {
            throw ValidationException::withMessages(['direction' => __('app.festival_direction_dependency_block')]);
        }
    }

    /** @param Collection<int, Model> $directions */
    private function moveWithin(Model $direction, Collection $directions, string $move): void
    {
        DB::transaction(function () use ($direction, $directions, $move): void {
            $directions = $directions->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
            foreach ($directions as $index => $item) {
                $item->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }

            $index = $directions->search(fn (Model $item): bool => $item->is($direction));
            $targetIndex = $move === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! $directions->has($targetIndex)) {
                return;
            }

            $target = $directions[$targetIndex];
            $currentOrder = $directions[$index]->sort_order;
            $directions[$index]->update(['sort_order' => $target->sort_order]);
            $target->update(['sort_order' => $currentOrder]);
        });
    }

    private function authorizeManager(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertDirection(Account $account, FestivalEdition $edition, FestivalDirection $direction): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($direction->account_id === $account->id && $direction->festival_edition_id === $edition->id, 404);
    }
}
