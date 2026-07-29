<?php

namespace App\Support;

use App\Enums\AccountApiTokenAbility;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\User;

class AccountApiTokenAbilityAuthorizer
{
    /**
     * @return array<int, AccountApiTokenAbility>
     */
    public function grantableAbilities(Account $account, ?User $user): array
    {
        if (! $user || ! $user->can('manageStudioSettings', $account)) {
            return [];
        }

        return array_values(array_filter(
            AccountApiTokenAbility::cases(),
            fn (AccountApiTokenAbility $ability): bool => $this->canGrant($account, $user, $ability),
        ));
    }

    public function canGrant(Account $account, User $user, AccountApiTokenAbility $ability): bool
    {
        return $user->can('manageStudioSettings', $account)
            && $account->userCan($user, $this->requiredPermission($ability));
    }

    public function canManageSecrets(Account $account, ?User $user, AccountApiToken $token): bool
    {
        if (! $user || ! $user->can('manageStudioSettings', $account)) {
            return false;
        }

        foreach ($token->abilityValues() as $abilityValue) {
            $ability = AccountApiTokenAbility::tryFrom($abilityValue);

            if (! $ability || ! $this->canGrant($account, $user, $ability)) {
                return false;
            }
        }

        return true;
    }

    public function requiredPermission(AccountApiTokenAbility $ability): StudioPermission
    {
        return match ($ability) {
            AccountApiTokenAbility::WebsiteLeadsCreate => StudioPermission::ManageWebsiteLeads,
            AccountApiTokenAbility::McpRead,
            AccountApiTokenAbility::McpLogicRead => StudioPermission::ManageStudioSettings,
            AccountApiTokenAbility::McpBookingsCreate,
            AccountApiTokenAbility::McpBookingsCancel => StudioPermission::ManageBookings,
            AccountApiTokenAbility::McpCustomersRead => StudioPermission::ManageClients,
            AccountApiTokenAbility::McpClassPassesRead => StudioPermission::ManageCustomerClassPasses,
            AccountApiTokenAbility::McpPaymentsRead => StudioPermission::ManageStudioCashflow,
            AccountApiTokenAbility::McpEventsRead => StudioPermission::ManageEvents,
        };
    }
}
