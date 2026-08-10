<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalActivityRecorder;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Actions\Festivals\RedeemFestivalEditionPurchase;
use App\Actions\Festivals\SaveFestivalEdition;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementStatus;
use App\Http\Requests\FestivalCategoryRequest;
use App\Http\Requests\FestivalChargeDefinitionRequest;
use App\Http\Requests\FestivalEditionRequest;
use App\Http\Requests\FestivalRequirementRequest;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalClassificationAxis;
use App\Models\FestivalContentSection;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalMedia;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflowStep;
use App\Support\Festivals\FestivalSaasAccess;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\StudioRulesHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FestivalStaffController extends Controller
{
    public function index(Request $request, Account $account, FestivalSaasAccess $saasAccess): View
    {
        $this->authorizeAccess($request, $account);
        $account->loadMissing('subscription.plan');
        $tab = in_array($request->query('tab'), ['festivals', 'series'], true)
            ? (string) $request->query('tab')
            : 'festivals';
        $canManage = (bool) $request->user()?->can('manageFestivals', $account);
        $isOwner = $account->isOwnedBy($request->user());
        $canPurchaseFestival = $isOwner && $saasAccess->canPurchase($account);

        abort_if($tab === 'series' && ! $canManage, 403);

        $editions = null;
        $series = null;
        $hasActiveSeries = null;

        if ($tab === 'festivals') {
            $hasNonJudgeAccess = collect([
                'manageFestivals',
                'manageFestivalRegistrations',
                'manageFestivalSchedule',
                'manageFestivalFinance',
                'checkInFestivalTickets',
            ])->contains(fn (string $ability): bool => (bool) $request->user()?->can($ability, $account));

            $editions = FestivalEdition::query()
                ->whereBelongsTo($account)
                ->when(! $hasNonJudgeAccess, fn ($query) => $query->whereHas('judgeAssignments', fn ($assignment) => $assignment
                    ->where('user_id', $request->user()?->id)
                    ->where('is_active', true)))
                ->with(['series', 'coverMedia'])
                ->withCount(['entries', 'admissionTypes'])
                ->latest('starts_at')
                ->paginate(12, ['*'], 'festivals_page')
                ->withQueryString();
            $hasActiveSeries = $canManage && FestivalSeries::query()
                ->whereBelongsTo($account)
                ->where('is_active', true)
                ->exists();
        } else {
            $series = FestivalSeries::query()
                ->whereBelongsTo($account)
                ->withCount('editions')
                ->orderBy('name')
                ->paginate(30, ['*'], 'series_page')
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
            'festivalPackages' => $canPurchaseFestival
                ? $account->subscription?->plan?->festivalTariffPackages()->where('is_active', true)->get() ?? collect()
                : collect(),
            'festivalPurchases' => $canManage
                ? FestivalEditionPurchase::query()->whereBelongsTo($account)->with(['plan', 'fiscalReceipt'])->latest()->paginate(10, ['*'], 'purchases_page')->withQueryString()
                : null,
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
        $this->storeHeroImage($request, $account, $edition);

        return redirect()->route('dashboard.accounts.festivals.show', [$account, $edition])->with('status', __('app.festival_edition_saved'));
    }

    public function show(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkspaceAccess $workspaceAccess): View
    {
        $this->assertEdition($account, $festivalEdition);
        $permissions = $workspaceAccess->permissions($request->user(), $account, $festivalEdition);
        abort_unless($workspaceAccess->canAccessWorkspace($permissions), 403);
        $festivalEdition->load('series')->loadCount(['entries', 'scheduleSlots', 'tickets']);

        $upcomingSlots = ($permissions['schedule'] || $permissions['judging'])
            ? $festivalEdition->scheduleSlots()->where('ends_at', '>=', now())->with(['stage', 'entry'])->orderBy('starts_at')->limit(6)->get()
            : collect();

        return view('festivals.staff.show', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'upcomingSlots' => $upcomingSlots,
            'entriesAwaitingReview' => $permissions['registrations']
                ? FestivalEntry::query()->where('festival_edition_id', $festivalEdition->id)->whereIn('status', [FestivalEntryStatus::Submitted->value, FestivalEntryStatus::UnderReview->value])->count()
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

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);

        $festivalEdition->load('coverMedia');

        return view('festivals.staff.form', [
            'account' => $account,
            'edition' => $festivalEdition,
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
        $this->storeHeroImage($request, $account, $festivalEdition);

        return redirect()->route('dashboard.accounts.festivals.show', [$account, $festivalEdition])->with('status', __('app.festival_edition_saved'));
    }

    public function storeCategory(FestivalCategoryRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validated();
        if (isset($data['festival_workflow_id'])) {
            abort_unless($festivalEdition->workflows()->whereKey($data['festival_workflow_id'])->exists(), 422);
        }
        $category = FestivalCategory::query()->updateOrCreate(
            ['festival_edition_id' => $festivalEdition->id, 'code' => $data['code']],
            ['account_id' => $account->id, ...$data, 'is_active' => $data['is_active'] ?? true],
        );
        $options = $festivalEdition->axes()->with('options')->get()->flatMap->options->whereIn('id', $data['option_ids'] ?? []);
        abort_unless($options->count() === count($data['option_ids'] ?? []), 422);
        $category->options()->sync($options->mapWithKeys(fn ($option): array => [$option->id => ['account_id' => $account->id]])->all());

        return redirect()->route('dashboard.accounts.festivals.settings.categories', [$account, $festivalEdition])->with('status', __('app.festival_category_saved'));
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
        $pricing = match ($data['pricing_mode']) {
            'flat_when_true' => ['mode' => 'flat_when_true', 'amount_cents' => (int) ($data['price_amount_cents'] ?? 0)],
            'per_unit' => ['mode' => 'per_unit', 'unit_amount_cents' => (int) ($data['price_amount_cents'] ?? 0)],
            'option_prices' => ['mode' => 'option_prices', 'prices' => $data['option_prices'] ?? []],
            default => ['mode' => 'none'],
        };
        unset($data['pricing_mode'], $data['price_amount_cents'], $data['option_prices']);
        FestivalRequirementDefinition::query()->updateOrCreate(
            ['festival_edition_id' => $festivalEdition->id, 'festival_category_id' => $data['festival_category_id'] ?? null, 'code' => $data['code']],
            ['account_id' => $account->id, ...$data, 'pricing' => $pricing, 'is_required' => $data['is_required'] ?? true, 'is_active' => $data['is_active'] ?? true],
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
        FestivalChargeDefinition::query()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, ...$data, 'currency' => $festivalEdition->currency, 'is_active' => $data['is_active'] ?? true]);

        return redirect()->route('dashboard.accounts.festivals.settings.fees', [$account, $festivalEdition])->with('status', __('app.festival_charge_saved'));
    }

    public function storeStage(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivalSchedule', $account), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000']]);
        $festivalEdition->stages()->create(['account_id' => $account->id, ...$data]);

        return redirect()->route('dashboard.accounts.festivals.program', [$account, $festivalEdition])->with('status', __('app.festival_stage_saved'));
    }

    public function storeAxis(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validate([
            'code' => ['required', 'alpha_dash:ascii', 'max:100'], 'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['direction', 'style', 'age', 'level', 'entry_format', 'custom'])],
            'options' => ['required', 'array', 'min:1'], 'options.*.code' => ['required', 'alpha_dash:ascii', 'max:100'],
            'options.*.label' => ['required', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($account, $festivalEdition, $data): void {
            $axis = FestivalClassificationAxis::query()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, 'code' => $data['code'], 'name' => $data['name'], 'kind' => $data['kind']]);
            foreach ($data['options'] as $index => $option) {
                $axis->options()->create(['account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id, ...$option, 'sort_order' => $index]);
            }
        });

        return redirect()->route('dashboard.accounts.festivals.settings', [$account, $festivalEdition])->with('status', __('app.festival_axis_saved'));
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

    public function storeAdmission(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivalFinance', $account), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'],
            'inventory' => ['required', 'integer', 'min:1'], 'price_cents' => ['required', 'integer', 'min:0'],
            'early_bird_price_cents' => ['nullable', 'integer', 'min:0'], 'early_bird_ends_at' => ['nullable', 'date'],
            'early_bird_quota' => ['nullable', 'integer', 'min:1'], 'max_per_order' => ['required', 'integer', 'min:1', 'max:20'],
            'sales_starts_at' => ['nullable', 'date'], 'sales_ends_at' => ['nullable', 'date', 'after:sales_starts_at'],
        ]);
        DB::transaction(function () use ($festivalEdition, $account, $data): void {
            $purchase = FestivalEditionPurchase::query()->where('festival_edition_id', $festivalEdition->id)->lockForUpdate()->first();
            if ($purchase && $festivalEdition->admissionTypes()->sum('inventory') + (int) $data['inventory'] > $purchase->max_tickets) {
                throw ValidationException::withMessages(['inventory' => __('app.festival_ticket_inventory_limit_exceeded', ['limit' => $purchase->max_tickets])]);
            }
            $festivalEdition->admissionTypes()->create(['account_id' => $account->id, ...$data]);
        }, 3);

        return redirect()->route('dashboard.accounts.festivals.tickets', [$account, $festivalEdition])->with('status', __('app.festival_admission_saved'));
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
        $festivalEntry->forceFill([
            ...$data, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
            'accepted_at' => $data['status'] === FestivalEntryStatus::Accepted->value ? now() : null,
            'rejected_at' => $data['status'] === FestivalEntryStatus::Rejected->value ? now() : null,
        ])->save();
        $activity->record($festivalEntry, 'entry.reviewed', $festivalEdition, $request->user(), $data);
        $notifications->queueForEntry($festivalEntry, 'entry_reviewed', ['entry_code' => $festivalEntry->code, 'status' => $data['status']], now()->getTimestamp().':'.$data['status']);

        return redirect()->route('dashboard.accounts.festivals.applications', [$account, $festivalEdition])->with('status', __('app.festival_entry_reviewed'));
    }

    public function reviewRequirement(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalEntryRequirement $festivalEntryRequirement, FestivalActivityRecorder $activity): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($festivalEntryRequirement->entry()->where('festival_edition_id', $festivalEdition->id)->exists(), 404);
        abort_unless($request->user()?->can('manageFestivalRegistrations', $account), 403);
        $data = $request->validate(['status' => ['required', Rule::in([FestivalRequirementStatus::Accepted->value, FestivalRequirementStatus::Rejected->value, FestivalRequirementStatus::Waived->value])], 'review_notes' => ['nullable', 'string', 'max:5000']]);
        DB::transaction(function () use ($festivalEntryRequirement, $request, $data, $activity, $festivalEdition): void {
            $requirement = FestivalEntryRequirement::query()->whereKey($festivalEntryRequirement->id)->lockForUpdate()->firstOrFail();
            $requirement->forceFill([...$data, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
            if ($submission = $requirement->submissions()->first()) {
                $submission->forceFill(['status' => $data['status'] === 'accepted' ? 'accepted' : ($data['status'] === 'rejected' ? 'rejected' : $submission->status), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $data['review_notes'] ?? null])->save();
            }
            $activity->record($requirement, 'requirement.reviewed', $festivalEdition, $request->user(), $data);
        });

        return redirect()->route('dashboard.accounts.festivals.applications', [$account, $festivalEdition])->with('status', __('app.festival_requirement_reviewed'));
    }

    public function approveManualCharge(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCharge $festivalCharge, FestivalActivityRecorder $activity): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($festivalCharge->entry()->where('festival_edition_id', $festivalEdition->id)->exists(), 404);
        abort_unless($request->user()?->can('manageFestivalFinance', $account), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'notes' => ['nullable', 'string', 'max:5000']]);
        $festivalCharge->forceFill(['status' => $data['decision'] === 'approve' ? 'paid' : 'failed', 'paid_at' => $data['decision'] === 'approve' ? now() : null, 'approved_by' => $request->user()->id, 'notes' => $data['notes'] ?? null])->save();
        $activity->record($festivalCharge, 'charge.manual_reviewed', $festivalEdition, $request->user(), $data);

        return redirect()->route('dashboard.accounts.festivals.applications', [$account, $festivalEdition])->with('status', __('app.festival_charge_reviewed'));
    }

    private function authorizeAccess(Request $request, Account $account): void
    {
        abort_unless(collect(['manageFestivals', 'manageFestivalRegistrations', 'manageFestivalSchedule', 'manageFestivalFinance', 'judgeFestivals', 'checkInFestivalTickets'])->contains(fn (string $ability): bool => (bool) $request->user()?->can($ability, $account)), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function storeHeroImage(FestivalEditionRequest $request, Account $account, FestivalEdition $edition): void
    {
        if (! $request->hasFile('hero_image')) {
            return;
        }

        $path = $request->file('hero_image')->store("festival-media/{$account->id}/{$edition->id}", 'public');
        $oldPaths = collect();

        try {
            DB::transaction(function () use ($account, $edition, $path, &$oldPaths): void {
                $previousCovers = FestivalMedia::query()
                    ->where('festival_edition_id', $edition->id)
                    ->where('is_cover', true)
                    ->lockForUpdate()
                    ->get();

                $oldPaths = $previousCovers
                    ->filter(fn (FestivalMedia $media): bool => $media->disk === 'public' && filled($media->path))
                    ->pluck('path');

                FestivalMedia::query()
                    ->where('festival_edition_id', $edition->id)
                    ->where('is_cover', true)
                    ->update(['is_cover' => false]);

                FestivalMedia::query()->create([
                    'account_id' => $account->id,
                    'festival_edition_id' => $edition->id,
                    'kind' => 'image',
                    'disk' => 'public',
                    'path' => $path,
                    'alt_text' => $edition->title,
                    'is_cover' => true,
                ]);

                $previousCovers
                    ->filter(fn (FestivalMedia $media): bool => filled($media->path))
                    ->each->delete();
            });
        } catch (Throwable $throwable) {
            Storage::disk('public')->delete($path);

            throw $throwable;
        }

        $oldPaths->each(fn (string $oldPath): bool => Storage::disk('public')->delete($oldPath));
    }
}
