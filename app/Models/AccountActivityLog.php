<?php

namespace App\Models;

use Database\Factories\AccountActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'action', 'route_name', 'method', 'status_code', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role', 'subject_type', 'subject_id', 'subject_label', 'url', 'ip_address', 'user_agent', 'occurred_at'])]
class AccountActivityLog extends Model
{
    /** @use HasFactory<AccountActivityLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
