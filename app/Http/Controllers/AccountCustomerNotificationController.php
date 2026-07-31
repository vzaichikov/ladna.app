<?php

namespace App\Http\Controllers;

use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountCustomerNotificationController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('viewActivityLog', $account);

        $purpose = $this->validEnumValue(SmsDeliveryPurpose::cases(), (string) $request->query('purpose'));
        $status = $this->validEnumValue(SmsDeliveryStatus::cases(), (string) $request->query('status'));
        $mode = $this->validEnumValue(SmsSendingMode::cases(), (string) $request->query('mode'));
        $provider = trim((string) $request->query('provider'));
        $dateFrom = $this->validDate((string) $request->query('date_from'));
        $dateTo = $this->validDate((string) $request->query('date_to'));
        $search = trim((string) $request->query('search'));

        $deliveries = $account->smsDeliveries()
            ->withLogDetails()
            ->when($purpose, fn (Builder $query): Builder => $query->where('sms_deliveries.purpose', $purpose))
            ->when($status, fn (Builder $query): Builder => $query->where('sms_deliveries.status', $status))
            ->when($mode, fn (Builder $query): Builder => $query->where('sms_deliveries.source_mode', $mode))
            ->when($provider !== '', fn (Builder $query): Builder => $query->where('sms_deliveries.provider', $provider))
            ->when($dateFrom, fn (Builder $query): Builder => $query->whereDate('sms_deliveries.created_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query): Builder => $query->whereDate('sms_deliveries.created_at', '<=', $dateTo))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('sms_deliveries.recipient_phone', 'like', '%'.$search.'%')
                    ->orWhere('sms_deliveries.provider_message_id', 'like', '%'.$search.'%')
                    ->orWhere('sms_deliveries.message_preview', 'like', '%'.$search.'%')
                    ->orWhere('sms_deliveries.last_error', 'like', '%'.$search.'%');
            }))
            ->latest('sms_deliveries.created_at')
            ->latest('sms_deliveries.id')
            ->paginate(25)
            ->withQueryString();

        return view('accounts.sms-deliveries', [
            'account' => $account,
            'deliveries' => $deliveries,
            'purposes' => SmsDeliveryPurpose::cases(),
            'statuses' => SmsDeliveryStatus::cases(),
            'modes' => SmsSendingMode::cases(),
            'providers' => $account->smsDeliveries()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider'),
            'filters' => compact('purpose', 'status', 'mode', 'provider', 'dateFrom', 'dateTo', 'search'),
        ]);
    }

    /**
     * @param  array<int, object>  $cases
     */
    private function validEnumValue(array $cases, string $value): ?string
    {
        return in_array($value, array_column($cases, 'value'), true) ? $value : null;
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
