<?php

namespace App\Http\Controllers;

use App\Actions\CreateManualScheduledClass;
use App\Actions\UpdateInternalScheduledClass;
use App\Enums\ScheduleKind;
use App\Http\Requests\StoreManualScheduledClassRequest;
use App\Http\Requests\UpdateInternalScheduledClassRequest;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Support\ScheduleKindRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ManualScheduledClassController extends Controller
{
    public function store(StoreManualScheduledClassRequest $request, Account $account, string $scheduleKind, CreateManualScheduledClass $createManualScheduledClass): RedirectResponse|JsonResponse
    {
        $scheduleKind = ScheduleKind::tryFrom($scheduleKind);
        abort_unless($scheduleKind && in_array($scheduleKind, ScheduleKindRegistry::oneOffRecordKinds(), true), 404);
        abort_unless($account->hasScheduleKindEnabled($scheduleKind), 404);

        try {
            $scheduledClass = $createManualScheduledClass->execute($account, $scheduleKind, $request->validated());
        } catch (QueryException $exception) {
            report($exception);

            return $this->creationFailedResponse($request);
        }

        if (! $this->classWasPersisted($account, $scheduledClass)) {
            return $this->creationFailedResponse($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('app.manual_class_created'),
                'scheduled_class_id' => $scheduledClass->id,
                'success_modal' => true,
                'modal_title' => __('app.manual_class_created_title'),
                'reload' => true,
            ], 201);
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.manual_class_created'));
    }

    public function update(
        UpdateInternalScheduledClassRequest $request,
        Account $account,
        ScheduledClass $scheduledClass,
        UpdateInternalScheduledClass $updateInternalScheduledClass,
    ): RedirectResponse|JsonResponse {
        abort_unless($scheduledClass->account_id === $account->id, 404);
        abort_unless($account->hasScheduleKindEnabled(ScheduleKind::InternalClass), 404);

        try {
            $updatedClass = $updateInternalScheduledClass->execute(
                $account,
                $scheduledClass,
                $request->validated(),
                $request->user(),
            );
        } catch (QueryException $exception) {
            report($exception);

            return $this->updateFailedResponse($request);
        }

        if (! $this->classWasPersisted($account, $updatedClass)) {
            return $this->updateFailedResponse($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('app.internal_class_updated'),
                'scheduled_class_id' => $updatedClass->id,
                'success_modal' => true,
                'modal_title' => __('app.internal_class_updated_title'),
                'reload' => true,
            ]);
        }

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.internal_class_updated'));
    }

    private function classWasPersisted(Account $account, ScheduledClass $scheduledClass): bool
    {
        return $scheduledClass->exists
            && $scheduledClass->getKey() !== null
            && $account->scheduledClasses()->whereKey($scheduledClass->getKey())->exists();
    }

    private function creationFailedResponse(StoreManualScheduledClassRequest $request): RedirectResponse|JsonResponse
    {
        $errors = [
            '_form' => [__('app.manual_class_create_failed')],
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('app.manual_class_create_failed'),
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()->withErrors($errors)->withInput();
    }

    private function updateFailedResponse(UpdateInternalScheduledClassRequest $request): RedirectResponse|JsonResponse
    {
        $errors = [
            '_form' => [__('app.internal_class_update_failed')],
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('app.internal_class_update_failed'),
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()->withErrors($errors)->withInput();
    }
}
