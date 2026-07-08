<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\Customer;
use App\Support\CustomerAuth\CustomerStudioAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request, CustomerStudioAccess $customerStudioAccess): View|RedirectResponse
    {
        return $this->createForLocale($request, $customerStudioAccess, 'uk');
    }

    public function createEnglish(Request $request, CustomerStudioAccess $customerStudioAccess): View|RedirectResponse
    {
        return $this->createForLocale($request, $customerStudioAccess, 'en');
    }

    private function createForLocale(Request $request, CustomerStudioAccess $customerStudioAccess, string $locale): View|RedirectResponse
    {
        App::setLocale($locale);
        Carbon::setLocale($locale);
        $request->session()->put('locale', $locale);

        $customer = $request->user('customer');

        if ($customer instanceof Customer && $destination = $customerStudioAccess->destinationFor($customer)) {
            return redirect()->to($destination);
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('app.auth_failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard.index', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
