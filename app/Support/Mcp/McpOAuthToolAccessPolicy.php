<?php

namespace App\Support\Mcp;

use App\Enums\AccountApiTokenAbility;
use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Mcp\Servers\LadnaStudioServer;
use App\Mcp\Tools\DescribeLadnaSkillsTool;
use App\Mcp\Tools\GetBusinessLogicReferenceTool;
use App\Mcp\Tools\GetCashboxOverviewTool;
use App\Mcp\Tools\GetClassBookingsForDayTool;
use App\Mcp\Tools\GetClassCountsForDayTool;
use App\Mcp\Tools\GetEarningsReportTool;
use App\Mcp\Tools\GetEventsOverviewTool;
use App\Mcp\Tools\GetEventSummaryTool;
use App\Mcp\Tools\GetFinancialReportTool;
use App\Mcp\Tools\GetOwnerHelpPageTool;
use App\Mcp\Tools\GetPaymentOverviewTool;
use App\Mcp\Tools\GetPayrollOverviewTool;
use App\Mcp\Tools\GetRentalReportTool;
use App\Mcp\Tools\GetStudioProfileTool;
use App\Mcp\Tools\InvestigateCustomerBookingLedgerTool;
use App\Mcp\Tools\SearchCustomersTool;
use App\Mcp\Tools\SearchOwnerHelpTool;
use App\Mcp\Tools\SearchPaymentsTool;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;

class McpOAuthToolAccessPolicy
{
    public function eligibleMembership(Account $account, User $user): ?AccountMembership
    {
        $membership = $account->membershipFor($user);

        if ($membership?->role === AccountRole::EventFestivalStaff) {
            return null;
        }

        return $membership;
    }

    public function canUseAbility(
        Account $account,
        User $user,
        AccountApiTokenAbility $ability,
        StudioPermission|array|null $requiredPermissions = null,
        bool $matchAll = true,
    ): bool {
        if (! $this->eligibleMembership($account, $user)) {
            return false;
        }

        $permissions = $requiredPermissions ?? $this->defaultPermissions($ability);

        if ($permissions === []) {
            return true;
        }

        $permissions = is_array($permissions) ? $permissions : [$permissions];
        $matches = collect($permissions)->filter(
            fn (StudioPermission $permission): bool => $account->userCan($user, $permission),
        )->count();

        return $matchAll ? $matches === count($permissions) : $matches > 0;
    }

    /**
     * @param  class-string  $toolClass
     */
    public function canUseTool(Account $account, User $user, string $toolClass): bool
    {
        $requirement = $this->toolRequirement($toolClass);

        if ($requirement === null) {
            return false;
        }

        [$ability, $permissions, $matchAll] = $requirement;

        return $this->canUseAbility($account, $user, $ability, $permissions, $matchAll);
    }

    /**
     * @param  array<int, class-string>  $toolClasses
     * @return array<int, class-string>
     */
    public function filterTools(Account $account, User $user, array $toolClasses): array
    {
        return array_values(array_filter(
            $toolClasses,
            fn (string $toolClass): bool => $this->canUseTool($account, $user, $toolClass),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function availableToolNames(Account $account, User $user): array
    {
        return collect($this->filterTools($account, $user, LadnaStudioServer::TOOL_CLASSES))
            ->map(fn (string $toolClass): string => app($toolClass)->name())
            ->values()
            ->all();
    }

    /**
     * @return array<int, StudioPermission>
     */
    private function defaultPermissions(AccountApiTokenAbility $ability): array
    {
        return match ($ability) {
            AccountApiTokenAbility::McpRead,
            AccountApiTokenAbility::McpLogicRead => [],
            AccountApiTokenAbility::McpBookingsCreate,
            AccountApiTokenAbility::McpBookingsCancel => [StudioPermission::ManageBookings],
            AccountApiTokenAbility::McpCustomersRead => [StudioPermission::ManageClients],
            AccountApiTokenAbility::McpClassPassesRead => [StudioPermission::ManageCustomerClassPasses],
            AccountApiTokenAbility::McpPaymentsRead => [StudioPermission::ViewStudioFinancialReports],
            AccountApiTokenAbility::McpCashflowRead => [StudioPermission::ManageStudioCashflow],
            AccountApiTokenAbility::McpPayrollRead => [StudioPermission::ManageStudioPayroll],
            AccountApiTokenAbility::McpEventsRead => [StudioPermission::ManageEvents],
            AccountApiTokenAbility::WebsiteLeadsCreate => [StudioPermission::ManageWebsiteLeads],
        };
    }

    /**
     * @param  class-string  $toolClass
     * @return array{AccountApiTokenAbility, array<int, StudioPermission>, bool}|null
     */
    private function toolRequirement(string $toolClass): ?array
    {
        return match ($toolClass) {
            GetClassCountsForDayTool::class => [AccountApiTokenAbility::McpRead, [StudioPermission::ManageSchedule, StudioPermission::ManageBookings], false],
            GetClassBookingsForDayTool::class => [AccountApiTokenAbility::McpCustomersRead, [StudioPermission::ManageBookings], true],
            SearchCustomersTool::class => [AccountApiTokenAbility::McpCustomersRead, [StudioPermission::ManageClients], true],
            InvestigateCustomerBookingLedgerTool::class => [AccountApiTokenAbility::McpClassPassesRead, [StudioPermission::ManageClients, StudioPermission::ManageCustomerClassPasses], true],
            GetPaymentOverviewTool::class,
            SearchPaymentsTool::class,
            GetFinancialReportTool::class,
            GetEarningsReportTool::class,
            GetRentalReportTool::class => [AccountApiTokenAbility::McpPaymentsRead, [StudioPermission::ViewStudioFinancialReports], true],
            GetCashboxOverviewTool::class => [AccountApiTokenAbility::McpCashflowRead, [StudioPermission::ManageStudioCashflow], true],
            GetPayrollOverviewTool::class => [AccountApiTokenAbility::McpPayrollRead, [StudioPermission::ManageStudioPayroll], true],
            GetEventsOverviewTool::class,
            GetEventSummaryTool::class => [AccountApiTokenAbility::McpEventsRead, [StudioPermission::ManageEvents], true],
            DescribeLadnaSkillsTool::class,
            GetStudioProfileTool::class,
            GetOwnerHelpPageTool::class,
            SearchOwnerHelpTool::class => [AccountApiTokenAbility::McpRead, [], true],
            GetBusinessLogicReferenceTool::class => [AccountApiTokenAbility::McpLogicRead, [], true],
            default => null,
        };
    }
}
