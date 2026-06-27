<?php

namespace App\Support;

use App\Models\Account;
use App\Models\User;

class ActorSnapshot
{
    /**
     * @return array{actor_user_id: int|null, actor_trainer_id: int|null, actor_name: string|null, actor_email: string|null, actor_role: string|null}
     */
    public function capture(Account $account, ?User $user): array
    {
        if (! $user) {
            return $this->empty();
        }

        $membership = $account->membershipFor($user);
        $trainerId = $account->trainers()
            ->whereBelongsTo($user, 'user')
            ->value('id');

        return [
            'actor_user_id' => $user->id,
            'actor_trainer_id' => $trainerId === null ? null : (int) $trainerId,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'actor_role' => $user->isPlatformAdmin() ? 'platform_admin' : $membership?->role?->value,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function prefixed(Account $account, ?User $user, string $prefix): array
    {
        $snapshot = [];

        foreach ($this->capture($account, $user) as $key => $value) {
            $snapshot[$prefix.'_'.substr($key, 6)] = $value;
        }

        return $snapshot;
    }

    /**
     * @return array{actor_user_id: null, actor_trainer_id: null, actor_name: null, actor_email: null, actor_role: null}
     */
    private function empty(): array
    {
        return [
            'actor_user_id' => null,
            'actor_trainer_id' => null,
            'actor_name' => null,
            'actor_email' => null,
            'actor_role' => null,
        ];
    }
}
