<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalActivityRecorder;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Actions\Festivals\RedeemFestivalEditionPurchase;
use App\Actions\Festivals\SaveFestivalEdition;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Http\Requests\FestivalChargeDefinitionRequest;
use App\Http\Requests\FestivalEditionRequest;
use App\Http\Requests\FestivalRequirementRequest;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalContentSection;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflowStep;
use App\Models\User;
use App\Support\EventFestivalStaffAccess;
use App\Support\Festivals\FestivalLandingRegistry;
use App\Support\Festivals\FestivalSaasAccess;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\Payments\PaymentAmounts;
use App\Support\StudioRulesHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalStaffController extends Controller
{
    public function index(
        Request $request,
        Account $account,
        FestivalSaasAccess $saasAccess,
        EventFestivalStaffAccess $staffAccess,
    ): View {
        $user = $request->user();
        $isEventFestivalStaff = $user instanceof User && $staffAccess->isStaff($user, $account);
        $this->authorizeAccess($request, $account, $isEventFestivalStaff);
        $canManage = (bool) $request->user()?->can('manageFestivals', $account);
        $isOwner = $account->isOwnedBy($request->user());
        $requestedTab = $request->query('tab');
        $tab = in_array($requestedTab, ['festivals', 'series', 'payments'], true)
            ? (string) $requestedTab
            : ($canManage && ! $account->festivalEditions()->exists() ? 'payments' : 'festivals');

        abort_if(in_array($tab, ['series', 'payments'], true) && ! $canManage, 403);

        $editions = null;
        $series = null;
        $festivalPackages = collect();
        $festivalPurchases = null;
        $hasActiveSeries = $canManage && in_array($tab, ['festivals', 'payments'], true)
            ? FestivalSeries::query()->whereBelongsTo($account)->where('is_active', true)->exists()
            : null;

        if ($tab === 'festivals') {
            $hasNonJudgeAccess = collect([
                'manageFestivals',
                'manageFestivalRegistrations',
                'manageFestivalSchedule',
                'manageFestivalFinance',
                'checkInFestivalTickets',
                'doorStaff',
            ])->contains(fn (string $ability): bool => (bool) $request->user()?->can($ability, $account));

            $editions = FestivalEdition::query()
                ->whereBelongsTo($account)
                ->when(! $hasNonJudgeAccess && ! $isEventFestivalStaff, fn ($query) => $query->whereHas('judgeAssignments', fn ($assignment) => $assignment
                    ->where('user_id', $request->user()?->id)
                    ->where('is_active', true)))
                ->when($isEventFestivalStaff, fn ($query) => $query
                    ->whereIn('status', [FestivalEditionStatus::Published->value, FestivalEditionStatus::InProgress->value])
                    ->where('ends_at', '>=', now()->subDay()))
                ->with(['series', 'coverMedia'])
                ->withCount(['entries', 'admissionTypes'])
                ->orderBy('starts_at', $isEventFestivalStaff ? 'asc' : 'desc')
                ->paginate(12, ['*'], 'festivals_page')
                ->withQueryString();
        } elseif ($tab === 'series') {
            $series = FestivalSeries::query()
                ->whereBelongsTo($account)
                ->withCount('editions')
                ->orderBy('name')
                ->paginate(30, ['*'], 'series_page')
                ->withQueryString();
        } else {
            $canPurchaseFestival = $isOwner && $saasAccess->canPurchase($account);
            $festivalPackages = $canPurchaseFestival
                ? $account->subscription?->plan?->festivalTariffPackages()->where('is_active', true)->get() ?? collect()
                : collect();
            $festivalPurchases = FestivalEditionPurchase::query()
                ->whereBelongsTo($account)
                ->with(['package.plan', 'edition' => fn ($query) => $query->whereBelongsTo($account)])
                ->latest()
                ->paginate(10, ['*'], 'purchases_page')
                ->withQueryString();
        }

        return view('festivals.staff.index', [
            'account' => $account,
            'tab' => $tab,
            'canManage' => $canManage,
            'series' => $series,
            'editions' => $editions,
            'hasActiveSeries' => $hasActiveSeries,
            'isOwner' => $isOwner,
            'festivalPackages' => $festivalPackages,
            'festivalPurchases' => $festivalPurchases,
            'isEventFestivalStaff' => $isEventFestivalStaff,
        ]);
    }

    public function create(Request $request, Account $account): View
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $purchase = FestivalEditionPurchase::query()
            ->whereBelongsTo($account)
            ->whereKey($request->integer('purchase'))
            ->where('status', FestivalEditionPurchaseStatus::Available->value)
            ->whereNull('festival_edition_id')
            ->firstOrFail();

        return view('festivals.staff.form', ['account' => $account, 'edition' => new FestivalEdition, 'purchase' => $purchase, 'series' => FestivalSeries::query()->whereBelongsTo($account)->where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(FestivalEditionRequest $request, Account $account, RedeemFestivalEditionPurchase $redeem): RedirectResponse
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $purchase = FestivalEditionPurchase::query()->whereBelongsTo($account)->findOrFail($request->integer('festival_purchase_id'));
        $edition = $redeem->execute($account, $purchase, $request->validated(), $request->user());

        return redirect()->route('dashboard.accounts.festivals.show', [$account, $edition])->with('status', __('app.festival_edition_saved'));
    }

    public function show(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkspaceAccess $workspaceAccess): View
    {
        $this->assertEdition($account, $festivalEdition);
        $permissions = $workspaceAccess->permissions($request->user(), $account, $festivalEdition);
        abort_unless($workspaceAccess->canAccessWorkspace($permissions), 403);
        $festivalEdition->load('series')->loadCount(['entries', 'scheduleSlots', 'tickets']);

        if ($permissions['manage']) {
            $festivalEdition->loadCount(['categories', 'judgeAssignments']);
        }

        $upcomingSlots = ($permissions['schedule'] || $permissions['judging'])
            ? $festivalEdition->scheduleSlots()
                ->whereNotNull('festival_entry_id')
                ->whereNotNull('starts_at')
                ->where('ends_at', '>=', now())
                ->with(['stage', 'entry'])
                ->orderBy('starts_at')
                ->limit(6)
                ->get()
            : collect();

        return view('festivals.staff.show', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'upcomingSlots' => $upcomingSlots,
            'festivalCriteriaCount' => $permissions['manage']
                ? FestivalRubricCriterion::query()
                    ->whereHas('section.rubric', fn ($query) => $query->where('festival_edition_id', $festivalEdition->id))
                    ->count()
                : null,
            'entriesAwaitingReview' => $permissions['registrations']
                ? FestivalEntry::query()->where('festival_edition_id', $festivalEdition->id)->whereIn('status', [FestivalEntryStatus::Submitted->value, FestivalEntryStatus::UnderReview->value, FestivalEntryStatus::ChangesPending->value])->count()
                : null,
            'requirementsAwaitingReview' => $permissions['registrations']
                ? $festivalEdition->festivalEntryRequirements()->where((new FestivalEntryRequirement)->qualifyColumn('status'), FestivalRequirementStatus::Submitted->value)->count()
                : null,
            'chargesAwaitingPayment' => $permissions['finance']
                ? $festivalEdition->festivalCharges()->whereIn((new FestivalCharge)->qualifyColumn('status'), [FestivalChargeStatus::Pending->value, FestivalChargeStatus::PaymentPending->value, FestivalChargeStatus::Failed->value])->count()
                : null,
            'ticketsCheckedIn' => ($permissions['finance'] || $permissions['ticket_check_in'])
                ? $festivalEdition->tickets()->where('is_checked_in', true)->count()
                : null,
        ]);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalLandingRegistry $landingRegistry): View
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);

        $festivalEdition->load(['coverMedia', 'mobileCoverMedia']);
        $activeTab = in_array($request->query('tab'), ['details', 'branding'], true)
            ? (string) $request->query('tab')
            : 'details';

        return view('festivals.staff.form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'activeTab' => $activeTab,
            'festivalLandingTemplates' => $landingRegistry->availableTemplates($account),
            'festivalLandingPalettes' => $landingRegistry->palettes(),
            'effectiveLandingTemplateKey' => $landingRegistry->effectiveTemplateKey($festivalEdition, $account),
            'effectiveLandingPaletteKey' => $landingRegistry->effectivePaletteKey($festivalEdition),
            'selectedLandingTemplateIsAvailable' => $landingRegistry->isTemplateAvailable($account, $festivalEdition->landing_template),
            'series' => FestivalSeries::query()
                ->whereBelongsTo($account)
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $festivalEdition->festival_series_id))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(FestivalEditionRequest $request, Account $account, FestivalEdition $festivalEdition, SaveFestivalEdition $save): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $festivalEdition = $save->execute($account, $request->validated(), $request->user(), $festivalEdition);

        return redirect()->route('dashboard.accounts.festivals.show', [$account, $festivalEdition])->with('status', __('app.festival_edition_saved'));
    }

    public function storeRequirement(FestivalRequirementRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validated();
        if (isset($data['festival_category_id'])) {
            abort_unless($festivalEdition->categories()->whereKey($data['festival_category_id'])->exists(), 422);
        }
        FestivalWorkflowStep::query()->whereKey($data['festival_workflow_step_id'])->whereHas('workflow', fn ($query) => $query->where('festival_edition_id', $festivalEdition->id))->firstOrFail();
        $options = collect($data['options'] ?? [])->map(fn (array $option): array => [
            'value' => $option['value'],
            'label' => $option['label'],
        ])->all();
        $optionPrices = collect($data['options'] ?? [])
            ->filter(fn (array $option): bool => filled($option['price'] ?? null))
            ->mapWithKeys(fn (array $option): array => [$option['value'] => (int) PaymentAmounts::decimalToCents($option['price'])])
            ->all();
        $amountCents = (int) PaymentAmounts::decimalToCents($data['price_amount'] ?? 0);
        $pricing = match ($data['pricing_mode']) {
            'flat_when_true' => ['mode' => 'flat_when_true', 'amount_cents' => $amountCents],
            'per_unit' => ['mode' => 'per_unit', 'unit_amount_cents' => $amountCents],
            'option_prices' => ['mode' => 'option_prices', 'prices' => $optionPrices],
            default => ['mode' => 'none'],
        };
        unset($data['pricing_mode'], $data['price_amount']);
        FestivalRequirementDefinition::query()->updateOrCreate(
            ['festival_edition_id' => $festivalEdition->id, 'festival_category_id' => $data['festival_category_id'] ?? null, 'code' => $data['code']],
            ['account_id' => $account->id, ...$data, 'options' => $options, 'pricing' => $pricing, 'is_required' => $data['is_required'] ?? true, 'is_active' => $data['is_active'] ?? true],
        );

        return redirect()->route('dashboard.accounts.festivals.settings.requirements', [$account, $festivalEdition])->with('status', __('app.festival_requirement_saved'));
    }

    public function storeChargeDefinition(FestivalChargeDefinitionRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivalFinance', $account), 403);
        $data = $request->validated();
        if (isset($data['festival_category_id'])) {
            abort_unless($festivalEdition->categories()->whereKey($data['festival_category_id'])->exists(), 422);
        }
        abort_unless(FestivalWorkflowStep::query()->whereKey($data['festival_workflow_step_id'])->whereHas('workflow', fn ($query) => $query->where('festival_edition_id', $festivalEdition->id))->exists(), 422);
        $data['amount_cents'] = (int) PaymentAmounts::decimalToCents($data['amount']);
        $data['additional_member_amount_cents'] = filled($data['additional_member_amount'] ?? null)
            ? (int) PaymentAmounts::decimalToCents($data['additional_member_amount'])
            : null;
        unset($data['amount'], $data['additional_member_amount']);
        FestivalChargeDefinition::query()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, ...$data, 'currency' => strtoupper($account->default_currency), 'is_active' => $data['is_active'] ?? true]);

        return redirect()->route('dashboard.accounts.festivals.settings.fees', [$account, $festivalEdition])->with('status', __('app.festival_charge_saved'));
    }

    public function storeContent(Request $request, Account $account, FestivalEdition $festivalEdition, StudioRulesHtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validate(['key' => ['required', 'alpha_dash:ascii', 'max:100'], 'title' => ['required', 'string', 'max:255'], 'body_html' => ['nullable', 'string', 'max:100000'], 'visibility' => ['required', Rule::in(['public', 'portal', 'staff'])]]);
        FestivalContentSection::query()->updateOrCreate(['festival_edition_id' => $festivalEdition->id, 'key' => $data['key']], ['account_id' => $account->id, ...$data, 'body_html' => $sanitizer->sanitize($data['body_html'] ?? null), 'is_active' => true]);

        return redirect()->route('dashboard.accounts.festivals.settings', [$account, $festivalEdition])->with('status', __('app.festival_content_saved'));
    }

    public function storeDocument(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'kind' => ['required', 'string', 'max:100'], 'visibility' => ['required', Rule::in(['public', 'portal', 'staff'])], 'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:51200']]);
        $file = $request->file('file');
        $path = $file->store("festivals/{$account->id}/editions/{$festivalEdition->id}/documents", 'local');
        FestivalDocument::query()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, 'title' => $data['title'], 'kind' => $data['kind'], 'visibility' => $data['visibility'], 'disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize()]);

        return redirect()->route('dashboard.accounts.festivals.settings', [$account, $festivalEdition])->with('status', __('app.festival_document_saved'));
    }

    public function storeMedia(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['image', 'video'])], 'external_url' => ['required', 'url:http,https', 'max:2048'], 'alt_text' => ['nullable', 'string', 'max:255'], 'caption' => ['nullable', 'string', 'max:500'], 'is_cover' => ['sometimes', 'boolean']]);
        if ($data['is_cover'] ?? false) {
            FestivalMedia::query()->where('festival_edition_id', $festivalEdition->id)->update(['is_cover' => false]);
        }
        FestivalMedia::query()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, ...$data, 'is_cover' => $data['is_cover'] ?? false]);

        return redirect()->route('dashboard.accounts.festivals.settings', [$account, $festivalEdition])->with('status', __('app.festival_media_saved'));
    }

    public function reviewEntry(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, FestivalActivityRecorder $activity, FestivalNotificationOutbox $notifications): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($festivalEntry->festival_edition_id === $festivalEdition->id, 404);
        abort_unless($request->user()?->can('manageFestivalRegistrations', $account), 403);
        abort_if($festivalEntry->steps()->exists(), 409, __('app.festival_step_reviewed'));
        $data = $request->validate([
            'status' => ['required', Rule::in([FestivalEntryStatus::UnderReview->value, FestivalEntryStatus::Accepted->value, FestivalEntryStatus::Rejected->value])],
            'qualification_status' => ['required', Rule::enum(FestivalQualificationStatus::class)],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        DB::transaction(function () use ($request, $festivalEdition, $festivalEntry, $activity, $notifications, $data): void {
            $festivalEntry = FestivalEntry::query()
                ->whereKey($festivalEntry->id)
                ->where('festival_edition_id', $festivalEdition->id)
                ->lockForUpdate()
                ->firstOrFail();
            $acceptingEntry = $data['status'] === FestivalEntryStatus::Accepted->value
                && $festivalEntry->status !== FestivalEntryStatus::Accepted;

            if ($acceptingEntry) {
                $category = FestivalCategory::query()
                    ->whereKey($festivalEntry->festival_category_id)
                    ->where('account_id', $festivalEntry->account_id)
                    ->where('festival_edition_id', $festivalEntry->festival_edition_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($category->applicationCapacityReached()) {
                    throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_full')]);
                }
            }

            $reviewedAt = now();
            $festivalEntry->forceFill([
                ...$data,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => $reviewedAt,
                'accepted_at' => $data['status'] === FestivalEntryStatus::Accepted->value ? ($festivalEntry->accepted_at ?? $reviewedAt) : null,
                'registration_completed_at' => $data['status'] === FestivalEntryStatus::Accepted->value ? ($festivalEntry->registration_completed_at ?? $reviewedAt) : null,
                'rejected_at' => $data['status'] === FestivalEntryStatus::Rejected->value ? $reviewedAt : null,
            ])->save();
            $activity->record($festivalEntry, 'entry.reviewed', $festivalEdition, $request->user(), $data);
            $notifications->queueForEntry($festivalEntry, FestivalNotificationType::EntryReviewed, [
                'status' => $data['status'],
                'comment' => $data['review_notes'] ?? null,
            ], $reviewedAt->getTimestamp().':'.$data['status']);
        }, 3);

        return redirect()->route('dashboard.accounts.festivals.applications', [$account, $festivalEdition])->with('status', __('app.festival_entry_reviewed'));
    }

    public function reviewRequirement(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalEntryRequirement $festivalEntryRequirement, FestivalActivityRecorder $activity, FestivalNotificationOutbox $notifications): JsonResponse|RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($festivalEntryRequirement->entry()->where('festival_edition_id', $festivalEdition->id)->exists(), 404);
        abort_unless($request->user()?->can('manageFestivalRegistrations', $account), 403);
        $data = $request->validate(['status' => ['required', Rule::in([FestivalRequirementStatus::Accepted->value, FestivalRequirementStatus::Rejected->value, FestivalRequirementStatus::Waived->value])], 'review_notes' => ['nullable', 'string', 'max:5000']]);
        $reviewDedupeToken = (string) Str::uuid();
        DB::transaction(function () use ($festivalEntryRequirement, $request, $data, $activity, $festivalEdition, $notifications, $reviewDedupeToken): void {
            $requirement = FestivalEntryRequirement::query()->with(['definition', 'submissions', 'entry.account', 'entry.edition', 'entry.portalUser', 'entryStep.workflowStep'])->whereKey($festivalEntryRequirement->id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === FestivalRequirementStatus::Waived->value
                && $requirement->definition->input_type === FestivalRequirementInputType::Agreement) {
                throw ValidationException::withMessages(['status' => __('app.festival_condition_confirmation_cannot_be_waived')]);
            }
            if ($data['status'] === FestivalRequirementStatus::Accepted->value && ! $requirement->hasSubmittedResponse()) {
                throw ValidationException::withMessages(['status' => __('app.festival_requirement_response_missing')]);
            }
            $requirement->forceFill([...$data, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
            if ($submission = $requirement->submissions()->first()) {
                $submission->forceFill(['status' => $data['status'] === 'accepted' ? 'accepted' : ($data['status'] === 'rejected' ? 'rejected' : $submission->status), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $data['review_notes'] ?? null])->save();
            }
            $activity->record($requirement, 'requirement.reviewed', $festivalEdition, $request->user(), $data);
            $notifications->queueForEntry($requirement->entry, FestivalNotificationType::RequirementReviewed, [
                'requirement' => $requirement->definition->name,
                'status' => $data['status'],
                'comment' => $data['review_notes'] ?? null,
                'action_url' => $requirement->entryStep
                    ? route('festival.portal.entry-steps.show', [$requirement->entry->account->slug, $requirement->entry, $requirement->entryStep])
                    : route('festival.portal.entries.show', [$requirement->entry->account->slug, $requirement->entry]),
            ], 'requirement-review:'.$requirement->id.':'.$reviewDedupeToken);
        });

        if ($request->expectsJson()) {
            $festivalEntryRequirement->refresh()->load(['definition', 'submissions']);

            return response()->json([
                'message' => __('app.festival_requirement_reviewed'),
                'fragment_html' => view('festivals.staff._application-requirement-review', [
                    'account' => $account,
                    'edition' => $festivalEdition,
                    'requirement' => $festivalEntryRequirement,
                ])->render(),
            ]);
        }

        return back()->with('status', __('app.festival_requirement_reviewed'));
    }

    public function approveManualCharge(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCharge $festivalCharge, FestivalActivityRecorder $activity, FestivalNotificationOutbox $notifications): JsonResponse|RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($festivalCharge->entry()->where('festival_edition_id', $festivalEdition->id)->exists(), 404);
        abort_unless($request->user()?->can('manageFestivalFinance', $account), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'notes' => ['nullable', 'string', 'max:5000']]);
        DB::transaction(function () use ($festivalCharge, $data, $request, $activity, $festivalEdition, $notifications, $account): void {
            $entry = FestivalEntry::query()
                ->whereKey($festivalCharge->festival_entry_id)
                ->where('festival_edition_id', $festivalEdition->id)
                ->lockForUpdate()
                ->firstOrFail();
            $hasLiveAttempt = FestivalPaymentAttempt::query()
                ->where('account_id', $account->id)
                ->where('status', FestivalPaymentStatus::Pending->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('allocations', fn ($query) => $query->where('festival_charge_id', $festivalCharge->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->exists();
            if ($hasLiveAttempt) {
                throw ValidationException::withMessages(['decision' => __('app.festival_payment_already_pending')]);
            }
            $charge = FestivalCharge::query()
                ->whereKey($festivalCharge->id)
                ->where('festival_entry_id', $entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($data['decision'] === 'approve' && $charge->due_at?->isPast()) {
                throw ValidationException::withMessages(['decision' => __('app.festival_step_deadline_expired')]);
            }
            $charge->forceFill(['status' => $data['decision'] === 'approve' ? 'paid' : 'failed', 'paid_at' => $data['decision'] === 'approve' ? now() : null, 'approved_by' => $request->user()->id, 'notes' => $data['notes'] ?? null])->save();
            $activity->record($charge, 'charge.manual_reviewed', $festivalEdition, $request->user(), $data);
            if ($data['decision'] === 'approve') {
                $notifications->queueForEntry($entry, FestivalNotificationType::PaymentPaid, [
                    'charge' => $charge->name,
                ], 'manual-charge-approved:'.$charge->id.':'.$charge->updated_at->getTimestamp());
            }
        }, 3);

        if ($request->expectsJson()) {
            $festivalCharge->refresh()->load('paymentAllocations.attempt.fiscalReceipt');

            return response()->json([
                'message' => __('app.festival_charge_reviewed'),
                'fragment_html' => view('festivals.staff._application-charge-review', [
                    'account' => $account,
                    'edition' => $festivalEdition,
                    'charge' => $festivalCharge,
                ])->render(),
            ]);
        }

        return back()->with('status', __('app.festival_charge_reviewed'));
    }

    private function authorizeAccess(Request $request, Account $account, bool $isEventFestivalStaff = false): void
    {
        abort_unless(
            $isEventFestivalStaff
                || collect(['manageFestivals', 'manageFestivalRegistrations', 'manageFestivalSchedule', 'manageFestivalFinance', 'judgeFestivals', 'checkInFestivalTickets', 'doorStaff'])
                    ->contains(fn (string $ability): bool => (bool) $request->user()?->can($ability, $account)),
            403,
        );
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }
}
