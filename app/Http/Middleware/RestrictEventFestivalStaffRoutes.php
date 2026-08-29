<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\User;
use App\Support\EventFestivalStaffAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictEventFestivalStaffRoutes
{
    /** @var list<string> */
    private const AllowedRoutes = [
        'dashboard.accounts.show',
        'dashboard.accounts.events.index',
        'dashboard.accounts.events.scanner',
        'dashboard.accounts.events.scanner.scan',
        'dashboard.accounts.events.attendance',
        'dashboard.accounts.events.attendance.data',
        'dashboard.accounts.events.attendance.tickets.undo',
        'dashboard.accounts.events.entrance.search',
        'dashboard.accounts.events.entrance.cash',
        'dashboard.accounts.events.entrance.card',
        'dashboard.accounts.events.entrance.poster',
        'dashboard.accounts.festivals.index',
        'dashboard.accounts.festivals.scanner',
        'dashboard.accounts.festivals.scanner.scan',
        'dashboard.accounts.festivals.attendance',
        'dashboard.accounts.festivals.attendance.data',
        'dashboard.accounts.festivals.attendance.tickets.undo',
        'dashboard.accounts.festivals.attendance.passes.undo',
        'dashboard.accounts.festivals.entrance.search',
        'dashboard.accounts.festivals.entrance.cash',
        'dashboard.accounts.festivals.entrance.card',
        'dashboard.accounts.festivals.entrance.poster',
        'dashboard.accounts.festivals.timeline.index',
        'dashboard.accounts.festivals.timeline.show',
        'dashboard.accounts.festivals.timeline.fragment',
        'dashboard.accounts.festivals.timeline.pause',
        'dashboard.accounts.festivals.timeline.resume',
        'dashboard.accounts.festivals.timeline.reorder',
        'dashboard.accounts.festivals.timeline.activate',
        'dashboard.accounts.festivals.timeline.toggle',
        'dashboard.accounts.festivals.online-stream.edit',
        'dashboard.accounts.festivals.online-stream.status',
        'dashboard.accounts.festivals.online-stream.preview',
        'dashboard.accounts.festivals.online-stream.update',
        'dashboard.accounts.festivals.online-stream.start',
        'dashboard.accounts.festivals.online-stream.stop',
        'dashboard.accounts.festivals.online-stream.reset-leases',
    ];

    public function __construct(private readonly EventFestivalStaffAccess $staffAccess) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->route('account');
        $user = $request->user();

        if ($account instanceof Account
            && $user instanceof User
            && $this->staffAccess->isStaff($user, $account)) {
            abort_unless(in_array($request->route()?->getName(), self::AllowedRoutes, true), 403);
        }

        return $next($request);
    }
}
