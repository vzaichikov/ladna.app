<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\View\View;

class ScheduledClassController extends Controller
{
    public function __invoke(Account $account): View
    {
        $this->authorize('view', $account);

        return view('scheduled-classes.index', [
            'account' => $account,
            'scheduledClasses' => $account->scheduledClasses()
                ->with(['location', 'room', 'classType.activityDirection', 'instructor', 'scheduleSeries'])
                ->orderBy('starts_at')
                ->limit(100)
                ->get(),
        ]);
    }
}
