<?php

namespace App\Enums;

enum AccountApiTokenAbility: string
{
    case WebsiteLeadsCreate = 'website_leads:create';
    case McpRead = 'mcp:read';
    case McpLogicRead = 'mcp:logic:read';
    case McpBookingsCreate = 'mcp:bookings:create';
    case McpBookingsCancel = 'mcp:bookings:cancel';
    case McpCustomersRead = 'mcp:customers:read';

    public function labelKey(): string
    {
        return 'app.account_api_token_ability_'.$this->value;
    }

    public function mutatesAccountData(): bool
    {
        return in_array($this, [
            self::WebsiteLeadsCreate,
            self::McpBookingsCreate,
            self::McpBookingsCancel,
        ], true);
    }
}
