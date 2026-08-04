<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

class WorkingLocationContext
{
    public const All = 'all';

    public const QueryKey = 'location_context';

    /** @var array<int, Collection<int, Location>> */
    private array $locations = [];

    /** @var array<int, string> */
    private array $values = [];

    public function __construct(private readonly Request $request) {}

    /**
     * @return Collection<int, Location>
     */
    public function locations(Account $account): Collection
    {
        return $this->locations[$account->id] ??= $account->locations()
            ->active()
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'is_active']);
    }

    public function value(Account $account): string
    {
        return $this->values[$account->id] ??= $this->resolveValue($account);
    }

    public function location(Account $account): ?Location
    {
        $value = $this->value($account);

        if ($value === self::All) {
            return null;
        }

        return $this->locations($account)->firstWhere('id', (int) $value);
    }

    public function selectedLocationId(Account $account): ?int
    {
        return $this->location($account)?->id;
    }

    public function formLocationId(Account $account): ?int
    {
        return $this->selectedLocationId($account)
            ?? ($this->locations($account)->count() === 1 ? $this->locations($account)->first()?->id : null);
    }

    public function filterLocationId(
        Account $account,
        string $queryKey = 'location_id',
        bool $includeInactive = false,
    ): ?int {
        if (! $this->request->query->has($queryKey)) {
            return $this->selectedLocationId($account);
        }

        $locationId = filter_var($this->request->query($queryKey), FILTER_VALIDATE_INT);

        $isAllowed = is_int($locationId) && ($includeInactive
            ? $account->locations()->whereKey($locationId)->exists()
            : $this->locations($account)->contains('id', $locationId));

        return $isAllowed
            ? $locationId
            : null;
    }

    public function cookie(Account $account, string $value): HttpCookie
    {
        return Cookie::forever(
            $this->cookieName($account),
            $this->normalizeValue($account, $value),
            path: '/',
            secure: $this->request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    public function cookieName(Account $account): string
    {
        return 'ladna_working_location_'.$account->id;
    }

    private function resolveValue(Account $account): string
    {
        if ($this->request->query->has(self::QueryKey)) {
            $value = $this->normalizeValue($account, (string) $this->request->query(self::QueryKey));
            Cookie::queue($this->cookie($account, $value));

            return $value;
        }

        return $this->normalizeValue(
            $account,
            (string) $this->request->cookie($this->cookieName($account), self::All),
        );
    }

    private function normalizeValue(Account $account, string $value): string
    {
        if ($value === self::All) {
            return self::All;
        }

        $locationId = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($locationId) || ! $this->locations($account)->contains('id', $locationId)) {
            return self::All;
        }

        return (string) $locationId;
    }
}
