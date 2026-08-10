<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Http\Requests\FestivalChargeDefinitionRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalRequirementRequest;
use App\Http\Requests\FestivalWorkflowRequest;
use App\Http\Requests\FestivalWorkflowStepRequest;
use App\Models\Account;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalEntryStep;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FestivalRegistrationSettingsController extends Controller
{
    public function storeWorkflow(FestivalWorkflowRequest $request, Account $account, FestivalEdition $festivalEdition, ProvisionFestivalWorkflow $provision): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $provision->execute($festivalEdition, $data['name'], $provision->standardSteps($data['application_review_mode'], $data['technical_review_mode']));

        return $this->redirect($account, $festivalEdition, 'workflows', __('app.festival_workflow_saved'));
    }

    public function updateWorkflow(FestivalWorkflowRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $data = $request->safe()->only(['name', 'is_active']);
        $data['is_active'] = $data['is_active'] ?? false;
        $festivalWorkflow->update($data);

        return $this->redirect($account, $festivalEdition, 'workflows', __('app.festival_workflow_saved'));
    }

    public function toggleWorkflow(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        if ($festivalWorkflow->is_active && $festivalWorkflow->categories()->exists()) {
            throw ValidationException::withMessages(['workflow' => __('app.festival_workflow_dependency_block')]);
        }
        $festivalWorkflow->update(['is_active' => ! $festivalWorkflow->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveWorkflow(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $this->move($festivalWorkflow, $festivalEdition->workflows()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function storeStep(FestivalWorkflowStepRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $data = $request->validated();
        $festivalWorkflow->steps()->create(['account_id' => $account->id, ...$data, 'is_active' => $data['is_active'] ?? true]);

        return $this->redirect($account, $festivalEdition, 'workflows', __('app.festival_workflow_step_saved'));
    }

    public function updateStep(FestivalWorkflowStepRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): RedirectResponse
    {
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $data = $request->validated();
        $festivalWorkflowStep->update([...$data, 'is_active' => $data['is_active'] ?? false]);

        return $this->redirect($account, $festivalEdition, 'workflows', __('app.festival_workflow_step_saved'));
    }

    public function toggleStep(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $referenced = $festivalWorkflowStep->requirementDefinitions()->exists()
            || $festivalWorkflowStep->chargeDefinitions()->exists()
            || FestivalEntryStep::query()->where('festival_workflow_step_id', $festivalWorkflowStep->id)->exists();
        if ($festivalWorkflowStep->is_active && $referenced) {
            throw ValidationException::withMessages(['step' => __('app.festival_workflow_step_dependency_block')]);
        }
        $festivalWorkflowStep->update(['is_active' => ! $festivalWorkflowStep->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveStep(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $this->move($festivalWorkflowStep, $festivalWorkflow->steps()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function storeRequirement(FestivalRequirementRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $this->requirementData($festivalEdition, $request->validated());
        FestivalRequirementDefinition::query()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, ...$data, 'is_required' => $data['is_required'] ?? true, 'is_active' => $data['is_active'] ?? true, 'sort_order' => $this->nextSort(FestivalRequirementDefinition::query()->where('festival_edition_id', $festivalEdition->id))]);

        return $this->redirect($account, $festivalEdition, 'requirements', __('app.festival_requirement_saved'));
    }

    public function updateRequirement(FestivalRequirementRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): RedirectResponse
    {
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);
        $data = $this->requirementData($festivalEdition, $request->validated());
        $festivalRequirementDefinition->update([...$data, 'is_required' => $data['is_required'] ?? false, 'is_active' => $data['is_active'] ?? false]);

        return $this->redirect($account, $festivalEdition, 'requirements', __('app.festival_requirement_saved'));
    }

    public function toggleRequirement(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);
        $festivalRequirementDefinition->update(['is_active' => ! $festivalRequirementDefinition->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveRequirement(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);
        $items = FestivalRequirementDefinition::query()->where('festival_edition_id', $festivalEdition->id)->get();
        $this->move($festivalRequirementDefinition, $items, $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function storeFee(FestivalChargeDefinitionRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $this->feeData($festivalEdition, $request->validated());
        FestivalChargeDefinition::query()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, ...$data, 'currency' => $festivalEdition->currency, 'is_active' => $data['is_active'] ?? true, 'sort_order' => $this->nextSort(FestivalChargeDefinition::query()->where('festival_edition_id', $festivalEdition->id))]);

        return $this->redirect($account, $festivalEdition, 'fees', __('app.festival_charge_saved'));
    }

    public function updateFee(FestivalChargeDefinitionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalChargeDefinition $festivalChargeDefinition): RedirectResponse
    {
        $this->assertFee($account, $festivalEdition, $festivalChargeDefinition);
        $data = $this->feeData($festivalEdition, $request->validated());
        $festivalChargeDefinition->update([...$data, 'currency' => $festivalEdition->currency, 'is_active' => $data['is_active'] ?? false]);

        return $this->redirect($account, $festivalEdition, 'fees', __('app.festival_charge_saved'));
    }

    public function toggleFee(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalChargeDefinition $festivalChargeDefinition): RedirectResponse
    {
        $this->authorizeFinance($request, $account);
        $this->assertFee($account, $festivalEdition, $festivalChargeDefinition);
        $festivalChargeDefinition->update(['is_active' => ! $festivalChargeDefinition->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function moveFee(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalChargeDefinition $festivalChargeDefinition): RedirectResponse
    {
        $this->authorizeFinance($request, $account);
        $this->assertFee($account, $festivalEdition, $festivalChargeDefinition);
        $items = FestivalChargeDefinition::query()->where('festival_edition_id', $festivalEdition->id)->get();
        $this->move($festivalChargeDefinition, $items, $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function requirementData(FestivalEdition $edition, array $data): array
    {
        $this->assertDependencies($edition, $data);
        $pricing = match ($data['pricing_mode']) {
            'flat_when_true' => ['mode' => 'flat_when_true', 'amount_cents' => (int) ($data['price_amount_cents'] ?? 0)],
            'per_unit' => ['mode' => 'per_unit', 'unit_amount_cents' => (int) ($data['price_amount_cents'] ?? 0)],
            'option_prices' => ['mode' => 'option_prices', 'prices' => $data['option_prices'] ?? []],
            default => ['mode' => 'none'],
        };
        unset($data['pricing_mode'], $data['price_amount_cents'], $data['option_prices'], $data['sort_order']);

        return [...$data, 'pricing' => $pricing];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function feeData(FestivalEdition $edition, array $data): array
    {
        $this->assertDependencies($edition, $data);
        unset($data['sort_order']);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function assertDependencies(FestivalEdition $edition, array $data): void
    {
        if (isset($data['festival_category_id'])) {
            abort_unless($edition->categories()->whereKey($data['festival_category_id'])->exists(), 422);
        }
        abort_unless(FestivalWorkflowStep::query()->whereKey($data['festival_workflow_step_id'])->whereHas('workflow', fn ($query) => $query->where('festival_edition_id', $edition->id))->exists(), 422);
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

    private function nextSort($query): int
    {
        return ((int) $query->max('sort_order')) + 10;
    }

    private function redirect(Account $account, FestivalEdition $edition, string $page, string $message): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.'.$page, [$account, $edition])->with('status', $message);
    }

    private function authorizeManager(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
    }

    private function authorizeFinance(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivalFinance', $account), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertWorkflow(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($workflow->account_id === $account->id && $workflow->festival_edition_id === $edition->id, 404);
    }

    private function assertStep(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow, FestivalWorkflowStep $step): void
    {
        $this->assertWorkflow($account, $edition, $workflow);
        abort_unless($step->account_id === $account->id && $step->festival_workflow_id === $workflow->id, 404);
    }

    private function assertRequirement(Account $account, FestivalEdition $edition, FestivalRequirementDefinition $requirement): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($requirement->account_id === $account->id && $requirement->festival_edition_id === $edition->id, 404);
    }

    private function assertFee(Account $account, FestivalEdition $edition, FestivalChargeDefinition $fee): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($fee->account_id === $account->id && $fee->festival_edition_id === $edition->id, 404);
    }
}
