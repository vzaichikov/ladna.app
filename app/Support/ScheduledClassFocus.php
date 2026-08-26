<?php

namespace App\Support;

use App\Models\Account;
use App\Models\ScheduledClass;
use Illuminate\Http\Request;

final class ScheduledClassFocus
{
    public function resolve(Request $request, Account $account): ?ScheduledClass
    {
        if (! $request->query->has('scheduled_class')) {
            return null;
        }

        $scheduledClassId = filter_var($request->query('scheduled_class'), FILTER_VALIDATE_INT);

        abort_unless(is_int($scheduledClassId), 404);

        $scheduledClass = $account->scheduledClasses()
            ->with('location:id,account_id,timezone')
            ->findOrFail($scheduledClassId);
        $scheduledClass->setRelation('account', $account);

        return $scheduledClass;
    }

    public function url(Account $account, ScheduledClass $scheduledClass): string
    {
        $routeName = $scheduledClass->ends_at->lessThanOrEqualTo(now())
            ? 'dashboard.accounts.scheduled-classes-history.index'
            : 'dashboard.accounts.scheduled-classes.index';

        return route($routeName, [
            'account' => $account,
            'scheduled_class' => $scheduledClass->id,
        ]).'#scheduled-class-'.$scheduledClass->id;
    }
}
