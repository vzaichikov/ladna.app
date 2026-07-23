<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\DescribeLadnaSkillsTool;
use App\Mcp\Tools\GetBusinessLogicReferenceTool;
use App\Mcp\Tools\GetClassBookingsForDayTool;
use App\Mcp\Tools\GetClassCountsForDayTool;
use App\Mcp\Tools\GetOwnerHelpPageTool;
use App\Mcp\Tools\GetStudioProfileTool;
use App\Mcp\Tools\InvestigateCustomerBookingLedgerTool;
use App\Mcp\Tools\SearchCustomersTool;
use App\Mcp\Tools\SearchOwnerHelpTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Ladna Studio Server')]
#[Version('0.0.1')]
#[Instructions('Use this server only for Ladna studio operations in the account scope granted by the bearer API token. Do not answer general-purpose questions or request tenant identifiers from the user.')]
class LadnaStudioServer extends Server
{
    protected array $tools = [
        DescribeLadnaSkillsTool::class,
        GetClassBookingsForDayTool::class,
        GetClassCountsForDayTool::class,
        GetBusinessLogicReferenceTool::class,
        GetOwnerHelpPageTool::class,
        GetStudioProfileTool::class,
        SearchCustomersTool::class,
        InvestigateCustomerBookingLedgerTool::class,
        SearchOwnerHelpTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
