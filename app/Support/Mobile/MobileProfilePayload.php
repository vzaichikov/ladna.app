<?php

namespace App\Support\Mobile;

use App\Enums\StudioPermission;
use App\Http\Resources\MobileAccountResource;
use App\Http\Resources\MobileCustomerResource;
use App\Models\AccountMembership;
use App\Models\MobileSession;

class MobileProfilePayload
{
    /**
     * @return array<string, mixed>
     */
    public function forSession(MobileSession $session): array
    {
        $session->loadMissing(['account.locations', 'user', 'customer']);

        return [
            'session' => [
                'id' => $session->id,
                'guard' => $session->guard,
                'role' => $session->role,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
            'account' => new MobileAccountResource($session->account),
            'actor' => $session->guard === MobileSession::GuardCustomer
                ? $this->customerActor($session)
                : $this->staffActor($session),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerActor(MobileSession $session): array
    {
        return [
            'type' => MobileSession::GuardCustomer,
            'customer' => new MobileCustomerResource($session->customer),
            'permissions' => [
                'book_classes',
                'cancel_own_bookings',
                'view_own_passes',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffActor(MobileSession $session): array
    {
        $membership = AccountMembership::query()
            ->whereBelongsTo($session->account)
            ->whereBelongsTo($session->user)
            ->first();
        $permissions = $membership
            ? collect(StudioPermission::cases())
                ->filter(fn (StudioPermission $permission): bool => $membership->allows($permission))
                ->map(fn (StudioPermission $permission): string => $permission->value)
                ->values()
                ->all()
            : [];
        $trainer = $session->account->trainers()
            ->where('user_id', $session->user_id)
            ->first();

        return [
            'type' => MobileSession::GuardStaff,
            'user' => [
                'id' => $session->user->id,
                'name' => $session->user->name,
                'email' => $session->user->email,
                'phone' => $session->user->phone,
                'avatar_url' => $session->user->avatarUrl(),
            ],
            'trainer' => $trainer ? [
                'id' => $trainer->id,
                'name' => $trainer->name,
                'photo_url' => $trainer->photoUrl(),
            ] : null,
            'permissions' => $permissions,
        ];
    }
}
