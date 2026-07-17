<?php

namespace App\Http\Middleware;

use App\Support\Http\AccountFromRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventReadOnlyDemoMutations
{
    public function __construct(private readonly AccountFromRequest $accountFromRequest) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $this->isSessionLifecycleRoute($request) || $this->isAssistantConversationRoute($request)) {
            return $next($request);
        }

        $account = $this->accountFromRequest->resolve($request);

        if (! $account?->isReadOnlyDemo()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => __('app.demo_readonly_message'),
                'code' => 'demo_readonly',
            ], Response::HTTP_LOCKED);
        }

        return redirect()
            ->back()
            ->withErrors(['demo' => __('app.demo_readonly_message')]);
    }

    private function isSessionLifecycleRoute(Request $request): bool
    {
        return in_array($request->route()?->getName(), [
            'logout',
            'customer.logout',
            'api.v1.mobile.logout',
        ], true);
    }

    private function isAssistantConversationRoute(Request $request): bool
    {
        return in_array($request->route()?->getName(), [
            'dashboard.accounts.assistant.messages.store',
            'dashboard.accounts.assistant.destroy',
        ], true);
    }
}
