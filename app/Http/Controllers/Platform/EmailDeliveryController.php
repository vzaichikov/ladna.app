<?php

namespace App\Http\Controllers\Platform;

use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailScenario;
use App\Enums\MailEngine;
use App\Http\Controllers\Controller;
use App\Models\EmailDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EmailDeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = $this->validValue((string) $request->query('status', ''), EmailDeliveryStatus::cases());
        $scenario = $this->validValue((string) $request->query('scenario', ''), EmailScenario::cases());
        $engine = $this->validValue((string) $request->query('engine', ''), MailEngine::cases());

        $deliveries = EmailDelivery::query()
            ->with(['account:id,name,slug,timezone', 'customer:id,name,email', 'user:id,name,email'])
            ->when($search !== '', fn (Builder $query): Builder => $this->applySearch($query, $search))
            ->when($status !== '', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($scenario !== '', fn (Builder $query): Builder => $query->where('scenario', $scenario))
            ->when($engine !== '', fn (Builder $query): Builder => $query->where('actual_engine', $engine))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('platform.email-deliveries.index', [
            'deliveries' => $deliveries,
            'engine' => $engine,
            'engines' => MailEngine::cases(),
            'scenario' => $scenario,
            'scenarios' => EmailScenario::cases(),
            'search' => $search,
            'status' => $status,
            'statuses' => EmailDeliveryStatus::cases(),
        ]);
    }

    public function preview(EmailDelivery $emailDelivery): Response
    {
        abort_if(blank($emailDelivery->html_body) && blank($emailDelivery->text_body), 404);

        $html = $emailDelivery->html_body;

        if (blank($html)) {
            $html = '<!doctype html><meta charset="utf-8"><pre style="white-space:pre-wrap;font:14px/1.6 system-ui,sans-serif">'
                .e($emailDelivery->text_body)
                .'</pre>';
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:");
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('recipient_name', 'like', '%'.$search.'%')
                ->orWhere('recipient_email', 'like', '%'.$search.'%')
                ->orWhere('subject', 'like', '%'.$search.'%')
                ->orWhere('provider_message_id', 'like', '%'.$search.'%')
                ->orWhere('last_error', 'like', '%'.$search.'%')
                ->orWhereHas('account', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%'));
        });
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     */
    private function validValue(string $value, array $cases): string
    {
        return in_array($value, array_column($cases, 'value'), true) ? $value : '';
    }
}
