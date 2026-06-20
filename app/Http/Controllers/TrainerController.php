<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Http\Requests\StoreTrainerRequest;
use App\Http\Requests\UpdateTrainerRequest;
use App\Models\Account;
use App\Models\Trainer;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TrainerController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);

        return view('trainers.index', [
            'account' => $account,
            'trainers' => $account->trainers()
                ->with('trainerType')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('manageTrainers', $account);
        $defaultTrainerType = $account->ensureDefaultTrainerType();

        return view('trainers.create', [
            'account' => $account,
            'trainer' => new Trainer([
                'trainer_type_id' => $defaultTrainerType->id,
                'is_active' => true,
            ]),
            ...$this->staffFormData($account),
        ]);
    }

    public function store(StoreTrainerRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')->store('trainer-photos/'.$account->id, 'public');
        }

        $trainer = $account->trainers()->create($this->trainerAttributes($validated));
        $this->syncLogin($account, $trainer, $validated);

        return redirect()->route('dashboard.accounts.trainers.index', $account)
            ->with('status', __('app.trainer_created'));
    }

    public function show(Account $account, Trainer $trainer): never
    {
        abort(404);
    }

    public function edit(Account $account, Trainer $trainer): View
    {
        $this->ensureBelongsToAccount($account, $trainer);
        $this->authorize('manageTrainers', $account);
        $account->ensureDefaultTrainerType();
        $trainer->loadMissing(['user', 'trainerType']);

        return view('trainers.edit', [
            'account' => $account,
            'trainer' => $trainer,
            ...$this->staffFormData($account, $trainer),
        ]);
    }

    public function update(UpdateTrainerRequest $request, Account $account, Trainer $trainer): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $trainer);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $trainer);
        $validated['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('photo')) {
            if ($trainer->photo_path) {
                Storage::disk('public')->delete($trainer->photo_path);
            }

            $validated['photo_path'] = $request->file('photo')->store('trainer-photos/'.$account->id, 'public');
        }

        $trainer->update($this->trainerAttributes($validated));
        $this->syncLogin($account, $trainer, $validated);

        return redirect()->route('dashboard.accounts.trainers.index', $account)
            ->with('status', __('app.trainer_updated'));
    }

    public function destroy(Account $account, Trainer $trainer): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $trainer);
        $this->authorize('manageTrainers', $account);

        if ($trainer->photo_path) {
            Storage::disk('public')->delete($trainer->photo_path);
        }

        if ($trainer->user_id) {
            $account->memberships()->where('user_id', $trainer->user_id)->delete();
        }

        $trainer->delete();

        return redirect()->route('dashboard.accounts.trainers.index', $account)
            ->with('status', __('app.trainer_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, Trainer $trainer): void
    {
        abort_unless($trainer->account_id === $account->id, 404);
    }

    private function uniqueSlug(Account $account, string $source, ?Trainer $ignore = null): string
    {
        return SlugGenerator::unique($source, 'trainer', fn (string $candidate): bool => $account->trainers()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function trainerAttributes(array $validated): array
    {
        return Arr::only($validated, [
            'name',
            'slug',
            'email',
            'phone',
            'bio',
            'trainer_type_id',
            'photo_path',
            'is_active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncLogin(Account $account, Trainer $trainer, array $validated): void
    {
        if (! (bool) ($validated['create_login'] ?? false)) {
            if ($trainer->user_id) {
                $account->memberships()->where('user_id', $trainer->user_id)->delete();
                $trainer->forceFill(['user_id' => null])->save();
            }

            return;
        }

        $user = $trainer->user ?: new User;
        $user->name = $trainer->name;
        $user->email = $validated['user_email'];

        if (! empty($validated['user_password'])) {
            $user->password = $validated['user_password'];
        }

        if (! $user->exists && empty($validated['user_password'])) {
            $user->password = Str::password(16);
        }

        $user->save();

        $permissions = array_values($validated['permissions'] ?? []);

        $account->memberships()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'role' => AccountRole::Trainer->value,
                'permissions' => $permissions,
            ],
        );

        $trainer->forceFill(['user_id' => $user->id])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function staffFormData(Account $account, ?Trainer $trainer = null): array
    {
        $membership = $trainer?->user_id
            ? $account->memberships()->where('user_id', $trainer->user_id)->first()
            : null;
        $role = $membership?->role ?? AccountRole::Trainer;
        $selectedPermissions = $membership?->permissions ?? array_map(
            fn (StudioPermission $permission): string => $permission->value,
            $role->defaultPermissions(),
        );

        return [
            'studioPermissions' => StudioPermission::cases(),
            'selectedPermissions' => $selectedPermissions,
            'trainerTypes' => $account->trainerTypes()->ordered()->get(),
        ];
    }
}
