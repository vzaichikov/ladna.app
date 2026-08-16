<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Http\Requests\StoreEventFestivalStaffRequest;
use App\Http\Requests\UpdateEventFestivalStaffRequest;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EventFestivalStaffController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('manageEventFestivalStaff', $account);

        $filters = [
            'q' => $request->string('q')->trim()->toString(),
        ];

        $staffMemberships = $account->memberships()
            ->where('role', AccountRole::EventFestivalStaff->value)
            ->with('user:id,name,email')
            ->when($filters['q'] !== '', fn ($query) => $query->whereHas('user', fn ($userQuery) => $userQuery
                ->where('name', 'like', '%'.$filters['q'].'%')
                ->orWhere('email', 'like', '%'.$filters['q'].'%')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('event-festival-staff.index', [
            'account' => $account,
            'staffMemberships' => $staffMemberships,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '',
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('manageEventFestivalStaff', $account);

        return view('event-festival-staff.create', [
            'account' => $account,
            'membership' => new AccountMembership,
            'user' => new User,
        ]);
    }

    public function store(StoreEventFestivalStaffRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($account, $validated): void {
            $user = User::create(Arr::only($validated, ['name', 'email', 'password']));

            $account->memberships()->create([
                'user_id' => $user->id,
                'role' => AccountRole::EventFestivalStaff->value,
                'permissions' => [],
            ]);
        });

        return redirect()->route('dashboard.accounts.event-festival-staff.index', $account)
            ->with('status', __('app.event_festival_staff_created'));
    }

    public function edit(Account $account, AccountMembership $membership): View
    {
        $this->ensureStaffMembership($account, $membership);
        $this->authorize('manageEventFestivalStaff', $account);
        $membership->loadMissing('user');

        return view('event-festival-staff.edit', [
            'account' => $account,
            'membership' => $membership,
            'user' => $membership->user,
        ]);
    }

    public function update(UpdateEventFestivalStaffRequest $request, Account $account, AccountMembership $membership): RedirectResponse
    {
        $this->ensureStaffMembership($account, $membership);
        $validated = $request->validated();

        DB::transaction(function () use ($membership, $validated): void {
            $user = $membership->user()->lockForUpdate()->firstOrFail();
            $attributes = Arr::only($validated, ['name', 'email']);

            if (filled($validated['password'] ?? null)) {
                $attributes['password'] = $validated['password'];
            }

            $user->update($attributes);
        });

        return redirect()->route('dashboard.accounts.event-festival-staff.index', $account)
            ->with('status', __('app.event_festival_staff_updated'));
    }

    public function destroy(Account $account, AccountMembership $membership): RedirectResponse
    {
        $this->ensureStaffMembership($account, $membership);
        $this->authorize('manageEventFestivalStaff', $account);
        $membership->delete();

        return redirect()->route('dashboard.accounts.event-festival-staff.index', $account)
            ->with('status', __('app.event_festival_staff_deleted'));
    }

    private function ensureStaffMembership(Account $account, AccountMembership $membership): void
    {
        abort_unless(
            $membership->account_id === $account->id
            && $membership->role === AccountRole::EventFestivalStaff,
            404,
        );
    }
}
