<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Customer;
use Illuminate\Support\Str;

class CustomerInvestigationSearch
{
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
                'phone_masked' => $this->maskedPhone($customer->phone),
                'email_masked' => $this->maskedEmail($customer->email),
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

    private function maskedPhone(?string $phone): ?string
    {
        $phone = filled($phone) ? trim((string) $phone) : null;

        if (! $phone) {
            return null;
        }

        $hiddenLength = max(0, Str::length($phone) - 4);

        return $hiddenLength > 0 ? Str::mask($phone, '•', 0, $hiddenLength) : $phone;
    }

    private function maskedEmail(?string $email): ?string
    {
        $email = filled($email) ? trim((string) $email) : null;

        if (! $email || ! Str::contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1).'***@'.$domain;
    }
}
