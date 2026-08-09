<?php

namespace App\Support\Festivals;

final class FestivalTariffDefaults
{
    /**
     * @return list<array{name: string, price_cents: int, currency: string, max_participants: int, max_tickets: int, is_active: bool, sort_order: int}>
     */
    public static function packages(): array
    {
        return [
            ['name' => 'S', 'price_cents' => 150000, 'currency' => 'UAH', 'max_participants' => 100, 'max_tickets' => 300, 'is_active' => true, 'sort_order' => 10],
            ['name' => 'M', 'price_cents' => 300000, 'currency' => 'UAH', 'max_participants' => 250, 'max_tickets' => 700, 'is_active' => true, 'sort_order' => 20],
            ['name' => 'L', 'price_cents' => 500000, 'currency' => 'UAH', 'max_participants' => 500, 'max_tickets' => 1500, 'is_active' => true, 'sort_order' => 30],
        ];
    }
}
