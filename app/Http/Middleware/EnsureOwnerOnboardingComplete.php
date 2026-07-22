<?php

namespace App\Http\Middleware;

use App\Enums\AccountRole;
use App\Models\AccountOnboarding;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerOnboardingComplete
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isPlatformAdmin()) {
            return $next($request);
        }

        $incompleteOnboarding = AccountOnboarding::query()
            ->whereHas('account.memberships', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('role', AccountRole::Owner->value))
            ->whereNull('completed_at')
            ->latest()
            ->first();

        if ($incompleteOnboarding) {
            return redirect()->route('onboarding.show', ['step' => $incompleteOnboarding->current_step]);
        }

        if (! $user->accounts()->exists() && $user->terms_accepted_at !== null) {
            return redirect()->route('onboarding.show', ['step' => 1]);
        }

        return $next($request);
    }
}
