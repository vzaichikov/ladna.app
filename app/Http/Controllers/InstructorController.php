<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstructorRequest;
use App\Http\Requests\UpdateInstructorRequest;
use App\Models\Account;
use App\Models\Instructor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InstructorController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);

        return view('instructors.index', [
            'account' => $account,
            'instructors' => $account->instructors()->orderBy('name')->get(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('update', $account);

        return view('instructors.create', [
            'account' => $account,
            'instructor' => new Instructor(['is_active' => true]),
        ]);
    }

    public function store(StoreInstructorRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $account->instructors()->create($validated);

        return redirect()->route('dashboard.accounts.instructors.index', $account)
            ->with('status', __('app.instructor_created'));
    }

    public function show(Account $account, Instructor $instructor): never
    {
        abort(404);
    }

    public function edit(Account $account, Instructor $instructor): View
    {
        $this->ensureBelongsToAccount($account, $instructor);
        $this->authorize('update', $account);

        return view('instructors.edit', [
            'account' => $account,
            'instructor' => $instructor,
        ]);
    }

    public function update(UpdateInstructorRequest $request, Account $account, Instructor $instructor): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $instructor);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $instructor);
        $validated['is_active'] = $request->boolean('is_active');

        $instructor->update($validated);

        return redirect()->route('dashboard.accounts.instructors.index', $account)
            ->with('status', __('app.instructor_updated'));
    }

    public function destroy(Account $account, Instructor $instructor): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $instructor);
        $this->authorize('update', $account);

        $instructor->delete();

        return redirect()->route('dashboard.accounts.instructors.index', $account)
            ->with('status', __('app.instructor_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, Instructor $instructor): void
    {
        abort_unless($instructor->account_id === $account->id, 404);
    }

    private function uniqueSlug(Account $account, string $source, ?Instructor $ignore = null): string
    {
        $slug = Str::slug($source) ?: 'instructor';
        $candidate = $slug;
        $suffix = 2;

        while ($account->instructors()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
