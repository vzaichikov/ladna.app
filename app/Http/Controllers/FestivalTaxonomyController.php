<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalCategoryRequest;
use App\Http\Requests\FestivalClassificationAxisRequest;
use App\Http\Requests\FestivalClassificationOptionRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalClassificationAxis;
use App\Models\FestivalClassificationOption;
use App\Models\FestivalEdition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FestivalTaxonomyController extends Controller
{
    public function storeAxis(FestivalClassificationAxisRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $festivalEdition->axes()->create(['account_id' => $account->id, ...$data, 'is_required' => $data['is_required'] ?? false, 'is_active' => $data['is_active'] ?? true, 'sort_order' => $this->nextSort($festivalEdition->axes())]);

        return $this->taxonomyRedirect($account, $festivalEdition, $data['kind'], __('app.festival_axis_saved'));
    }

    public function updateAxis(FestivalClassificationAxisRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalClassificationAxis $festivalClassificationAxis): RedirectResponse
    {
        $this->assertAxis($account, $festivalEdition, $festivalClassificationAxis);
        $data = $request->validated();
        $festivalClassificationAxis->update([...$data, 'is_required' => $data['is_required'] ?? false, 'is_active' => $data['is_active'] ?? false]);

        return $this->taxonomyRedirect($account, $festivalEdition, $festivalClassificationAxis->kind, __('app.festival_axis_saved'));
    }

    public function toggleAxis(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalClassificationAxis $festivalClassificationAxis): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertAxis($account, $festivalEdition, $festivalClassificationAxis);
        if ($festivalClassificationAxis->is_active && $festivalClassificationAxis->options()->whereHas('categories')->exists()) {
            throw ValidationException::withMessages(['axis' => __('app.festival_axis_dependency_block')]);
        }
        $festivalClassificationAxis->update(['is_active' => ! $festivalClassificationAxis->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveAxis(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalClassificationAxis $festivalClassificationAxis): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertAxis($account, $festivalEdition, $festivalClassificationAxis);
        $this->move($festivalClassificationAxis, $festivalEdition->axes()->where('kind', $festivalClassificationAxis->kind)->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function storeOption(FestivalClassificationOptionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalClassificationAxis $festivalClassificationAxis): RedirectResponse
    {
        $this->assertAxis($account, $festivalEdition, $festivalClassificationAxis);
        $data = $request->validated();
        $festivalClassificationAxis->options()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, ...$data, 'is_active' => $data['is_active'] ?? true, 'sort_order' => $this->nextSort($festivalClassificationAxis->options())]);

        return back()->with('status', __('app.festival_option_saved'));
    }

    public function updateOption(FestivalClassificationOptionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalClassificationAxis $festivalClassificationAxis, FestivalClassificationOption $festivalClassificationOption): RedirectResponse
    {
        $this->assertOption($account, $festivalEdition, $festivalClassificationAxis, $festivalClassificationOption);
        $data = $request->validated();
        $festivalClassificationOption->update([...$data, 'is_active' => $data['is_active'] ?? false]);

        return back()->with('status', __('app.festival_option_saved'));
    }

    public function toggleOption(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalClassificationAxis $festivalClassificationAxis, FestivalClassificationOption $festivalClassificationOption): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertOption($account, $festivalEdition, $festivalClassificationAxis, $festivalClassificationOption);
        if ($festivalClassificationOption->is_active && $festivalClassificationOption->categories()->exists()) {
            throw ValidationException::withMessages(['option' => __('app.festival_option_dependency_block')]);
        }
        $festivalClassificationOption->update(['is_active' => ! $festivalClassificationOption->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveOption(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalClassificationAxis $festivalClassificationAxis, FestivalClassificationOption $festivalClassificationOption): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertOption($account, $festivalEdition, $festivalClassificationAxis, $festivalClassificationOption);
        $this->move($festivalClassificationOption, $festivalClassificationAxis->options()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function storeCategory(FestivalCategoryRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $category = $festivalEdition->categories()->create(['account_id' => $account->id, ...$this->categoryData($festivalEdition, $data), 'is_active' => $data['is_active'] ?? true, 'sort_order' => $this->nextSort($festivalEdition->categories())]);
        $this->syncOptions($account, $festivalEdition, $category, $data['option_ids'] ?? []);

        return redirect()->route('dashboard.accounts.festivals.settings.categories', [$account, $festivalEdition])->with('status', __('app.festival_category_saved'));
    }

    public function updateCategory(FestivalCategoryRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): RedirectResponse
    {
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $data = $request->validated();
        $festivalCategory->update([...$this->categoryData($festivalEdition, $data), 'is_active' => $data['is_active'] ?? false]);
        $this->syncOptions($account, $festivalEdition, $festivalCategory, $data['option_ids'] ?? []);

        return redirect()->route('dashboard.accounts.festivals.settings.categories', [$account, $festivalEdition])->with('status', __('app.festival_category_saved'));
    }

    public function toggleCategory(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $festivalCategory->update(['is_active' => ! $festivalCategory->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveCategory(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $this->move($festivalCategory, $festivalEdition->categories()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function categoryData(FestivalEdition $edition, array $data): array
    {
        if (isset($data['festival_workflow_id'])) {
            abort_unless($edition->workflows()->whereKey($data['festival_workflow_id'])->exists(), 422);
        }
        unset($data['option_ids'], $data['sort_order']);

        return $data;
    }

    /** @param array<int, int|string> $optionIds */
    private function syncOptions(Account $account, FestivalEdition $edition, FestivalCategory $category, array $optionIds): void
    {
        $options = FestivalClassificationOption::query()->where('festival_edition_id', $edition->id)->whereKey($optionIds)->get();
        abort_unless($options->count() === count($optionIds), 422);
        $category->options()->sync($options->mapWithKeys(fn (FestivalClassificationOption $option): array => [$option->id => ['account_id' => $account->id]])->all());
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
            $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || ! $items->has($targetIndex)) {
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

    private function taxonomyRedirect(Account $account, FestivalEdition $edition, string $kind, string $message): RedirectResponse
    {
        $route = $kind === 'direction' ? 'dashboard.accounts.festivals.settings.directions' : 'dashboard.accounts.festivals.settings.classifications';

        return redirect()->route($route, [$account, $edition])->with('status', $message);
    }

    private function authorizeManager(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertAxis(Account $account, FestivalEdition $edition, FestivalClassificationAxis $axis): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($axis->account_id === $account->id && $axis->festival_edition_id === $edition->id, 404);
    }

    private function assertOption(Account $account, FestivalEdition $edition, FestivalClassificationAxis $axis, FestivalClassificationOption $option): void
    {
        $this->assertAxis($account, $edition, $axis);
        abort_unless($option->festival_classification_axis_id === $axis->id && $option->festival_edition_id === $edition->id, 404);
    }

    private function assertCategory(Account $account, FestivalEdition $edition, FestivalCategory $category): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($category->account_id === $account->id && $category->festival_edition_id === $edition->id, 404);
    }
}
