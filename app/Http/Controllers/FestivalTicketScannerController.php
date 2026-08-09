<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalTicketScanner;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalTicket;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalTicketScannerController extends Controller
{
    public function show(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkspaceAccess $workspaceAccess): View
    {
        $this->authorizeScanner($request, $account, $festivalEdition);
        $search = trim($request->string('search')->toString());
        $tickets = FestivalTicket::query()->where('festival_edition_id', $festivalEdition->id)->with(['admissionType', 'order:id,buyer_name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhereHas('order', fn ($query) => $query->where('buyer_name', 'like', "%{$search}%"))))
            ->orderBy('code')->paginate(50)->withQueryString();

        return view('festivals.staff.scanner', compact('account', 'festivalEdition', 'tickets', 'search') + [
            'workspacePermissions' => $workspaceAccess->permissions($request->user(), $account, $festivalEdition),
        ]);
    }

    public function scan(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalTicketScanner $scanner): JsonResponse
    {
        $this->authorizeScanner($request, $account, $festivalEdition);
        $data = $request->validate(['code' => ['required', 'string', 'max:2048'], 'source' => ['nullable', 'in:qr,manual,door_list']]);
        $result = $scanner->checkIn($festivalEdition, $data['code'], $request->user(), $data['source'] ?? 'qr', $request->ip());
        $status = match ($result['state']) {
            'invalid' => 404, 'already_checked_in' => 409, 'wrong_edition', 'void' => 422, default => 200
        };

        return response()->json($result, $status);
    }

    public function checkOut(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalTicket $festivalTicket, FestivalTicketScanner $scanner): JsonResponse
    {
        $this->authorizeScanner($request, $account, $festivalEdition);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $result = $scanner->checkOut($festivalEdition, $festivalTicket, $request->user(), $data['reason'], $request->ip());

        return response()->json($result, $result['state'] === 'checked_out' ? 200 : 422);
    }

    private function authorizeScanner(Request $request, Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
        abort_unless($request->user()?->can('checkInFestivalTickets', $account), 403);
    }
}
