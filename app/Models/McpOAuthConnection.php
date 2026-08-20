<?php

namespace App\Models;

use Database\Factories\McpOAuthConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

#[Fillable(['account_id', 'user_id', 'oauth_client_id', 'client_name', 'last_used_at', 'revoked_at'])]
class McpOAuthConnection extends Model
{
    /** @use HasFactory<McpOAuthConnectionFactory> */
    use HasFactory;

    protected $table = 'mcp_oauth_connections';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
