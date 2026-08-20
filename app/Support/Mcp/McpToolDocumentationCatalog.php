<?php

namespace App\Support\Mcp;

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
use Laravel\Mcp\Server\Tool;

class McpToolDocumentationCatalog
{
    /** @var array<string, array<int, class-string<Tool>>> */
    private const GROUPS = [
        'studio' => [
            DescribeLadnaSkillsTool::class,
            GetStudioProfileTool::class,
        ],
        'schedule' => [
            GetClassCountsForDayTool::class,
            GetClassBookingsForDayTool::class,
        ],
        'customers' => [
            SearchCustomersTool::class,
            InvestigateCustomerBookingLedgerTool::class,
        ],
        'finance' => [
            GetPaymentOverviewTool::class,
            SearchPaymentsTool::class,
            GetFinancialReportTool::class,
            GetEarningsReportTool::class,
            GetRentalReportTool::class,
            GetCashboxOverviewTool::class,
            GetPayrollOverviewTool::class,
        ],
        'events' => [
            GetEventsOverviewTool::class,
            GetEventSummaryTool::class,
        ],
        'help' => [
            SearchOwnerHelpTool::class,
            GetOwnerHelpPageTool::class,
            GetBusinessLogicReferenceTool::class,
        ],
    ];

    /**
     * @return array<int, array{key: string, title: string, tools: array<int, array{name: string, description: string}>}>
     */
    public function groups(): array
    {
        return collect(self::GROUPS)
            ->map(function (array $toolClasses, string $group): array {
                return [
                    'key' => $group,
                    'title' => __('app.api_docs_mcp_group_'.$group),
                    'tools' => collect($toolClasses)
                        ->map(function (string $toolClass): array {
                            $tool = app($toolClass);

                            return [
                                'name' => $tool->name(),
                                'description' => (string) $tool->description(),
                            ];
                        })
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, class-string<Tool>>
     */
    public function toolClasses(): array
    {
        return collect(self::GROUPS)->flatten()->values()->all();
    }
}
