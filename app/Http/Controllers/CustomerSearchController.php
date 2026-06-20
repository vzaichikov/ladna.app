<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerSearchController extends Controller
{
    public function __invoke(Request $request, Account $account): JsonResponse
    {
        $this->authorize('manageBookings', $account);

        $term = trim((string) $request->query('q', ''));

        $customers = $account->customers()
            ->select(['id', 'name', 'phone', 'email'])
            ->when($term !== '', function ($query) use ($term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn ($customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'label' => collect([$customer->name, $customer->phone, $customer->email])
                    ->filter()
                    ->implode(' · '),
            ]);

        return response()->json($customers);
    }
}
