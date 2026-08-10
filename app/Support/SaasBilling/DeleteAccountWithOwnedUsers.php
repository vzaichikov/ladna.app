<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountRole;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\FestivalEntry;
use App\Models\User;
use App\Support\Ai\AiConversationImageCleaner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeleteAccountWithOwnedUsers
{
    public function __construct(
        private readonly AiConversationImageCleaner $imageCleaner,
    ) {}

    public function execute(Account $account): void
    {
        $this->imageCleaner->deleteForAccount($account);

        DB::transaction(function () use ($account): void {
            $ownedUsers = $this->ownedUsers($account);

            FestivalEntry::query()->whereBelongsTo($account)->delete();
            $account->delete();

            $ownedUsers->each(fn (User $user): ?bool => $user->delete());
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function ownedUsers(Account $account): Collection
    {
        return User::query()
            ->whereHas('accountMemberships', fn ($query) => $query
                ->whereBelongsTo($account)
                ->where('role', AccountRole::Owner->value))
            ->whereDoesntHave('accountMemberships', fn ($query) => $query
                ->where('account_id', '!=', $account->id))
            ->where(fn ($query) => $query
                ->whereNull('system_role')
                ->orWhere('system_role', '!=', SystemRole::PlatformAdmin->value))
            ->get();
    }
}
