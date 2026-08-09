<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SubmitFestivalEntry;
use App\Enums\FestivalEntryStatus;
use App\Http\Requests\FestivalEntryRequest;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Festivals\FestivalRuleRegistry;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FestivalEntryController extends Controller
{
    public function create(Request $request, string $accountSlug, string $editionSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)->firstOrFail();
        abort_unless($edition->registrationIsOpen(), 403);

        return view('festivals.portal.entry-form', [
            'account' => $account, 'portalUser' => $portalUser, 'edition' => $edition, 'entry' => new FestivalEntry,
            'categories' => $edition->categories()->where('is_active', true)->with('options.axis')->orderBy('name')->get(),
            'participants' => $portalUser->participants()->whereNull('archived_at')->orderBy('last_name')->get(),
        ]);
    }

    public function store(FestivalEntryRequest $request, string $accountSlug, string $editionSlug, FestivalRuleRegistry $rules): RedirectResponse
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->published()->where('slug', $editionSlug)->firstOrFail();
        $entry = $this->saveDraft($request, $edition, $portalUser, $rules);

        return redirect()->route('festival.portal.entries.show', [$accountSlug, $entry])->with('status', __('app.festival_entry_draft_saved'));
    }

    public function show(Request $request, string $accountSlug, FestivalEntry $festivalEntry): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $this->assertEntry($festivalEntry, $portalUser);
        $festivalEntry->load(['edition', 'category.options.axis', 'participants', 'requirements.submissions', 'charges.paymentAttempts', 'scheduleSlots.stage', 'result', 'scoreSheets.assignment', 'scoreSheets.scores.criterion.section']);
        $providers = app(PaymentGatewayRegistry::class)->availableSettingsFor($account);

        return view('festivals.portal.entry', ['account' => $account, 'portalUser' => $portalUser, 'entry' => $festivalEntry, 'providers' => $providers]);
    }

    public function edit(Request $request, string $accountSlug, FestivalEntry $festivalEntry): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $this->assertEntry($festivalEntry, $portalUser);
        abort_unless($festivalEntry->status === FestivalEntryStatus::Draft, 409);
        $edition = $festivalEntry->edition;

        return view('festivals.portal.entry-form', [
            'account' => $account, 'portalUser' => $portalUser, 'edition' => $edition, 'entry' => $festivalEntry->load('participants'),
            'categories' => $edition->categories()->where('is_active', true)->with('options.axis')->orderBy('name')->get(),
            'participants' => $portalUser->participants()->whereNull('archived_at')->orderBy('last_name')->get(),
        ]);
    }

    public function update(FestivalEntryRequest $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalRuleRegistry $rules): RedirectResponse
    {
        [, $portalUser] = $this->context($request, $accountSlug);
        $this->assertEntry($festivalEntry, $portalUser);
        abort_unless($festivalEntry->status === FestivalEntryStatus::Draft, 409);
        $this->saveDraft($request, $festivalEntry->edition, $portalUser, $rules, $festivalEntry);

        return redirect()->route('festival.portal.entries.show', [$accountSlug, $festivalEntry])->with('status', __('app.festival_entry_draft_saved'));
    }

    public function submit(Request $request, string $accountSlug, FestivalEntry $festivalEntry, SubmitFestivalEntry $submit): RedirectResponse
    {
        [, $portalUser] = $this->context($request, $accountSlug);
        $this->assertEntry($festivalEntry, $portalUser);
        $submit->execute($festivalEntry);

        return back()->with('status', __('app.festival_entry_submitted'));
    }

    public function withdraw(Request $request, string $accountSlug, FestivalEntry $festivalEntry): RedirectResponse
    {
        [, $portalUser] = $this->context($request, $accountSlug);
        $this->assertEntry($festivalEntry, $portalUser);
        abort_unless(! in_array($festivalEntry->status, [FestivalEntryStatus::Withdrawn, FestivalEntryStatus::Rejected], true), 409);
        $festivalEntry->forceFill(['status' => FestivalEntryStatus::Withdrawn, 'withdrawn_at' => now()])->save();

        return back()->with('status', __('app.festival_entry_withdrawn'));
    }

    public function payCharge(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalCharge $festivalCharge, FestivalPaymentService $payments): RedirectResponse|View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $this->assertEntry($festivalEntry, $portalUser);
        abort_unless($festivalCharge->festival_entry_id === $festivalEntry->id && $festivalCharge->account_id === $account->id, 404);
        $data = $request->validate(['provider' => ['required', 'string', 'max:50']]);

        try {
            $checkout = $payments->startCharge($festivalCharge, $data['provider']);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        return $checkout->isRedirect() ? redirect()->away($checkout->url) : view('payments.redirect-form', compact('account', 'checkout'));
    }

    private function saveDraft(FestivalEntryRequest $request, FestivalEdition $edition, FestivalPortalUser $portalUser, FestivalRuleRegistry $rules, ?FestivalEntry $entry = null): FestivalEntry
    {
        $data = $request->validated();
        $category = FestivalCategory::query()->whereKey($data['festival_category_id'])->where('festival_edition_id', $edition->id)->where('account_id', $portalUser->account_id)->where('is_active', true)->firstOrFail();
        $participants = FestivalParticipant::query()->where('festival_portal_user_id', $portalUser->id)->where('account_id', $portalUser->account_id)->whereNull('archived_at')->whereKey($data['participant_ids'])->get();
        if ($participants->count() !== count($data['participant_ids'])) {
            throw ValidationException::withMessages(['participant_ids' => __('app.festival_participant_invalid')]);
        }
        $rules->validateEntry($edition, $category, $participants);

        return DB::transaction(function () use ($entry, $edition, $portalUser, $category, $participants, $data): FestivalEntry {
            $entry ??= new FestivalEntry;
            $entry->fill([
                'account_id' => $edition->account_id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id,
                'festival_category_id' => $category->id, 'code' => $entry->code ?? 'FEN-'.str()->upper(str()->random(12)),
                'performer_name' => $data['performer_name'], 'act_title' => $data['act_title'] ?? null,
                'act_description' => $data['act_description'] ?? null, 'coach_name_snapshot' => $portalUser->displayName(),
                'studio_name_snapshot' => $portalUser->studio_name, 'comments' => $data['comments'] ?? null,
            ])->save();
            $sync = $participants->values()->mapWithKeys(fn (FestivalParticipant $participant, int $index): array => [$participant->id => [
                'account_id' => $edition->account_id, 'sort_order' => $index,
                'age_snapshot' => (int) $participant->date_of_birth->diffInYears($edition->age_reference_date),
                'name_snapshot' => $participant->displayName(),
            ]])->all();
            $entry->participants()->sync($sync);

            return $entry->refresh();
        }, 3);
    }

    private function assertEntry(FestivalEntry $entry, FestivalPortalUser $portalUser): void
    {
        abort_unless($entry->festival_portal_user_id === $portalUser->id && $entry->account_id === $portalUser->account_id, 404);
    }

    /** @return array{Account, FestivalPortalUser} */
    private function context(Request $request, string $slug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $slug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id, 404);

        return [$account, $portalUser];
    }
}
