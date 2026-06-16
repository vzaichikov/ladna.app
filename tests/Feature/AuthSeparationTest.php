<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthSeparationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_internal_registration_creates_user_not_customer(): void
    {
        $this->post('/register', [
            'name' => 'Internal User',
            'email' => 'internal@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard.accounts.create'));

        $this->assertTrue(User::where('email', 'internal@example.com')->exists());
        $this->assertFalse(Customer::where('email', 'internal@example.com')->exists());
    }

    public function test_customer_guard_does_not_access_internal_dashboard(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->get('/dashboard')
            ->assertRedirect('/login');
    }
}
