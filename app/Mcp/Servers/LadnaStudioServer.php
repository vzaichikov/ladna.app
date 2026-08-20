<?php

namespace App\Mcp\Servers;

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
use App\Support\Mcp\McpAccountContext;
use App\Support\Mcp\McpOAuthToolAccessPolicy;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Ladna Studio Server')]
#[Version('0.0.1')]
#[Instructions('Use this server only for Ladna studio operations in the connected studio scope. Respect the live permissions of the connected person. Do not answer general-purpose questions or request tenant identifiers from the user.')]
class LadnaStudioServer extends Server
{
    public const TOOL_CLASSES = [
        DescribeLadnaSkillsTool::class,
        GetClassBookingsForDayTool::class,
        GetClassCountsForDayTool::class,
        GetEventsOverviewTool::class,
        GetEventSummaryTool::class,
        GetBusinessLogicReferenceTool::class,
        GetCashboxOverviewTool::class,
        GetEarningsReportTool::class,
        GetOwnerHelpPageTool::class,
        GetFinancialReportTool::class,
        GetPaymentOverviewTool::class,
        GetPayrollOverviewTool::class,
        GetRentalReportTool::class,
        GetStudioProfileTool::class,
        SearchCustomersTool::class,
        InvestigateCustomerBookingLedgerTool::class,
        SearchOwnerHelpTool::class,
        SearchPaymentsTool::class,
    ];

    protected array $tools = self::TOOL_CLASSES;

    protected array $resources = [];

    protected array $prompts = [];

    protected function boot(): void
    {
        $context = app(McpAccountContext::class);

        if (! $context->isOAuth()) {
            return;
        }

        $this->tools = app(McpOAuthToolAccessPolicy::class)->filterTools(
            $context->account(),
            $context->actorUser(),
            $this->tools,
        );
    }
}
