<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainerTypeRequest;
use App\Http\Requests\UpdateTrainerTypeRequest;
use App\Models\Account;
use App\Models\TrainerType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TrainerTypeController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('update', $account);
        $account->ensureDefaultTrainerType();

        return view('trainer-types.index', [
            'account' => $account,
            'iconOptions' => config('icons.trainer_types'),
            'trainerTypes' => $account->trainerTypes()->ordered()->get(),
        ]);
    }

    public function store(StoreTrainerTypeRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['is_default'] = $request->boolean('is_default') || ! $account->trainerTypes()->exists();

        DB::transaction(function () use ($account, $validated): void {
            if ($validated['is_default']) {
                $account->trainerTypes()->update(['is_default' => false]);
            }

            $account->trainerTypes()->create($this->trainerTypeAttributes($validated));
            $account->ensureDefaultTrainerType();
        });

        return redirect()
            ->route('dashboard.accounts.trainer-types.index', $account)
            ->with('status', __('app.trainer_type_created'));
    }

    public function update(UpdateTrainerTypeRequest $request, Account $account, TrainerType $trainerType): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $trainerType);

        $validated = $request->validated();
        $validated['is_default'] = $request->boolean('is_default') || $trainerType->is_default;

        DB::transaction(function () use ($account, $trainerType, $validated): void {
            if ($validated['is_default']) {
                $account->trainerTypes()
                    ->whereKeyNot($trainerType->getKey())
                    ->update(['is_default' => false]);
            }

            $trainerType->update($this->trainerTypeAttributes($validated));
            $account->ensureDefaultTrainerType();
        });

        return redirect()
            ->route('dashboard.accounts.trainer-types.index', $account)
            ->with('status', __('app.trainer_type_updated'));
    }

    public function destroy(Account $account, TrainerType $trainerType): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $trainerType);
        $this->authorize('update', $account);

        if ($trainerType->is_default || $account->trainerTypes()->count() <= 1) {
            return redirect()
                ->route('dashboard.accounts.trainer-types.index', $account)
                ->withErrors(['trainer_type' => __('app.trainer_type_cannot_delete_default')]);
        }

        DB::transaction(function () use ($account, $trainerType): void {
            $defaultTrainerType = $account->ensureDefaultTrainerType();

            $trainerType->trainers()->update(['trainer_type_id' => $defaultTrainerType->id]);
            $trainerType->classPassPlans()
                ->withCount('trainerTypes')
                ->get()
                ->each(function ($classPassPlan) use ($defaultTrainerType): void {
                    if ($classPassPlan->trainer_types_count <= 1) {
                        $classPassPlan->trainerTypes()->syncWithoutDetaching([$defaultTrainerType->id]);
                    }
                });

            $trainerType->delete();
        });

        return redirect()
            ->route('dashboard.accounts.trainer-types.index', $account)
            ->with('status', __('app.trainer_type_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, TrainerType $trainerType): void
    {
        abort_unless($trainerType->account_id === $account->id, 404);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function trainerTypeAttributes(array $validated): array
    {
        return Arr::only($validated, [
            'name',
            'icon',
            'color',
            'is_default',
            'sort_order',
        ]);
    }
}
