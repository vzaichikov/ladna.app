<?php

namespace App\Models;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use Database\Factories\AccountMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['account_id', 'user_id', 'role', 'permissions'])]
class AccountMembership extends Pivot
{
    /** @use HasFactory<AccountMembershipFactory> */
    use HasFactory;

    protected $table = 'account_memberships';

    public $incrementing = true;

    protected $attributes = [
        'role' => 'owner',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
            'permissions' => 'array',
        ];
    }

    public function allows(StudioPermission|string $permission): bool
    {
        $permissionValue = $permission instanceof StudioPermission ? $permission->value : $permission;

        if ($this->role === AccountRole::Owner) {
            return true;
        }

        $permissions = $this->permissions;

        if ($permissions === null) {
            $permissions = array_map(
                fn (StudioPermission $permission): string => $permission->value,
                $this->role->defaultPermissions(),
            );
        }

        return in_array($permissionValue, $permissions, true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
