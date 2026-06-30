<?php

namespace App\Http\Controllers;

use App\Actions\ExportCustomers;
use App\Actions\ImportCustomers;
use App\Http\Requests\ImportCustomersRequest;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerBulkTransferController extends Controller
{
    public function export(Account $account, ExportCustomers $exportCustomers): StreamedResponse
    {
        $this->authorize('manageClients', $account);

        return $exportCustomers->customers($account);
    }

    public function example(Account $account, ExportCustomers $exportCustomers): StreamedResponse
    {
        $this->authorize('manageClients', $account);

        return $exportCustomers->example();
    }

    public function import(ImportCustomersRequest $request, Account $account, ImportCustomers $importCustomers): JsonResponse
    {
        try {
            return response()->json($importCustomers->execute($account, $request->file('file')));
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        }
    }

    public function validateImport(ImportCustomersRequest $request, Account $account, ImportCustomers $importCustomers): JsonResponse
    {
        try {
            $importCustomers->validateFile($request->file('file'));

            return response()->json(['valid' => true]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        }
    }
}
