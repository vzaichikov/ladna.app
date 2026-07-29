<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Customer;
use Illuminate\Support\Str;

class CustomerInvestigationSearch
{
    public function __construct(private readonly MaskedContactPresenter $maskedContact) {}

    /**
     * @return array<string, mixed>
     */
    public function search(Account $account, string $query, int $limit = 5): array
    {
        $query = Str::of($query)->squish()->toString();
        $limit = min(max($limit, 1), 10);
        $escapedQuery = addcslashes($query, '\\%_');
        $phoneFragment = preg_replace('/\D+/', '', $query) ?: '';

        $customers = Customer::query()
            ->whereBelongsTo($account)
            ->select(['id', 'account_id', 'name', 'phone', 'email'])
            ->where(function ($customerQuery) use ($escapedQuery, $phoneFragment): void {
                $customerQuery->where('name', 'like', '%'.$escapedQuery.'%')
                    ->orWhere('phone', 'like', '%'.$escapedQuery.'%');

                if (mb_strlen($phoneFragment) >= 3 && $phoneFragment !== $escapedQuery) {
                    $customerQuery->orWhere('phone', 'like', '%'.addcslashes($phoneFragment, '\\%_').'%');
                }
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $truncated = $customers->count() > $limit;
        $matches = $customers
            ->take($limit)
            ->map(fn (Customer $customer): array => [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'phone_masked' => $this->maskedContact->phone($customer->phone),
                'email_masked' => $this->maskedContact->email($customer->email),
            ])
            ->values()
            ->all();

        return [
            'status' => match (count($matches)) {
                0 => 'not_found',
                1 => 'unique',
                default => 'ambiguous',
            },
            'query' => $query,
            'matches' => $matches,
            'truncated' => $truncated,
        ];
    }
}
