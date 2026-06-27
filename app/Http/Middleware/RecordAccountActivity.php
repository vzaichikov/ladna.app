<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\AccountActivityLog;
use App\Support\AccountActivityLogSettings;
use App\Support\ActorSnapshot;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RecordAccountActivity
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->record($request, $response);

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if ($response->getStatusCode() >= 400 || $request->session()->has('errors')) {
            return;
        }

        if (! AccountActivityLogSettings::enabled()) {
            return;
        }

        $account = $request->route('account');

        if (! $account instanceof Account) {
            return;
        }

        $routeName = $request->route()?->getName();
        $subject = $this->subject($request, $account);

        AccountActivityLog::create([
            'account_id' => $account->id,
            'action' => $routeName ?? $request->path(),
            'route_name' => $routeName,
            'method' => $request->method(),
            'status_code' => $response->getStatusCode(),
            ...$this->actorSnapshot->capture($account, $request->user()),
            ...$subject,
            'url' => Str::limit($request->fullUrl(), 2048, ''),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array{subject_type: string|null, subject_id: int|string|null, subject_label: string|null}
     */
    private function subject(Request $request, Account $account): array
    {
        $subject = collect($request->route()?->parameters() ?? [])
            ->reverse()
            ->first(fn (mixed $parameter): bool => $parameter instanceof Model && ! $parameter instanceof Account);

        if (! $subject instanceof Model) {
            $subject = $account;
        }

        return [
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_label' => $this->subjectLabel($subject),
        ];
    }

    private function subjectLabel(Model $subject): string
    {
        foreach (['name', 'title', 'code', 'email', 'phone', 'slug'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}
