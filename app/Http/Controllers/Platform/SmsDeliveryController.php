<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SmsDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SmsDeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $purpose = $this->validEnumValue(SmsDeliveryPurpose::cases(), (string) $request->query('purpose'));
        $status = $this->validEnumValue(SmsDeliveryStatus::cases(), (string) $request->query('status'));
        $mode = $this->validEnumValue(SmsSendingMode::cases(), (string) $request->query('mode'));
        $provider = trim((string) $request->query('provider'));
        $accountId = filter_var($request->query('account_id'), FILTER_VALIDATE_INT) ?: null;
        $dateFrom = $this->validDate((string) $request->query('date_from'));
        $dateTo = $this->validDate((string) $request->query('date_to'));

        $deliveries = SmsDelivery::query()
            ->withLogDetails()
            ->with('account:id,name,timezone')
            ->when($purpose, fn (Builder $query): Builder => $query->where('sms_deliveries.purpose', $purpose))
            ->when($status, fn (Builder $query): Builder => $query->where('sms_deliveries.status', $status))
            ->when($mode, fn (Builder $query): Builder => $query->where('sms_deliveries.source_mode', $mode))
            ->when($provider !== '', fn (Builder $query): Builder => $query->where('sms_deliveries.provider', $provider))
            ->when($accountId, fn (Builder $query): Builder => $query->where('sms_deliveries.account_id', $accountId))
            ->when($dateFrom, fn (Builder $query): Builder => $query->whereDate('sms_deliveries.created_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query): Builder => $query->whereDate('sms_deliveries.created_at', '<=', $dateTo))
            ->latest('sms_deliveries.created_at')
            ->latest('sms_deliveries.id')
            ->paginate(50)
            ->withQueryString();

        return view('platform.sms-deliveries.index', [
            'deliveries' => $deliveries,
            'purposes' => SmsDeliveryPurpose::cases(),
            'statuses' => SmsDeliveryStatus::cases(),
            'modes' => SmsSendingMode::cases(),
            'providers' => SmsDelivery::query()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider'),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name']),
            'filters' => compact('purpose', 'status', 'mode', 'provider', 'accountId', 'dateFrom', 'dateTo'),
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
