<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalRequirementInputType;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FestivalApplicationMediaReport
{
    /** @return array{categories: Collection<int, FestivalCategory>, filters: array{q: string, category: string}} */
    public function filterData(Request $request, Account $account, FestivalEdition $edition): array
    {
        $categories = FestivalCategory::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->with('direction')
            ->orderBy('name')
            ->get();
        $requestedCategory = $request->integer('category');

        return [
            'categories' => $categories,
            'filters' => [
                'q' => $request->string('q')->trim()->limit(100)->toString(),
                'category' => $requestedCategory > 0 && $categories->contains('id', $requestedCategory)
                    ? (string) $requestedCategory
                    : '',
            ],
        ];
    }

    /**
     * @param  array{q: string, category: string}  $filters
     * @return LengthAwarePaginator<int, FestivalEntry>
     */
    public function paginate(Account $account, FestivalEdition $edition, array $filters): LengthAwarePaginator
    {
        $searchTerms = preg_split('/\s+/u', $filters['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $markedDefinition = fn ($query) => $query
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->where('show_in_media_report', true);
        $eligibleRequirement = fn (Builder $query): Builder => $query
            ->where('account_id', $account->id)
            ->whereHas('definition', fn (Builder $query): Builder => $markedDefinition($query)
                ->where('input_type', FestivalRequirementInputType::File->value))
            ->whereHas('latestSubmission', fn (Builder $query): Builder => $query
                ->where('account_id', $account->id)
                ->whereNotNull('disk')
                ->whereNotNull('path')
                ->whereIn('mime_type', FestivalSubmission::playableMimeTypes()));

        return FestivalEntry::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->whereIn('status', [
                FestivalEntryStatus::Draft->value,
                FestivalEntryStatus::Submitted->value,
                FestivalEntryStatus::UnderReview->value,
                FestivalEntryStatus::ChangesPending->value,
                FestivalEntryStatus::Accepted->value,
            ])
            ->whereHas('requirements', $eligibleRequirement)
            ->when($searchTerms !== [], fn (Builder $query) => $query->where(function (Builder $query) use ($searchTerms): void {
                foreach ($searchTerms as $term) {
                    $search = '%'.$term.'%';
                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('code', 'like', $search)
                            ->orWhere('entry_name', 'like', $search)
                            ->orWhere('act_title', 'like', $search)
                            ->orWhereHas('portalUser', fn (Builder $query) => $query
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('email', 'like', $search));
                    });
                }
            }))
            ->when($filters['category'] !== '', fn (Builder $query) => $query->where('festival_category_id', (int) $filters['category']))
            ->with([
                'portalUser' => fn ($query) => $query->where('account_id', $account->id),
                'category' => fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->where('festival_edition_id', $edition->id)
                    ->with('direction'),
                'requirements' => fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->whereHas('definition', $markedDefinition)
                    ->with([
                        'definition' => $markedDefinition,
                        'participant' => fn ($query) => $query->where('account_id', $account->id),
                        'latestSubmission' => fn ($query) => $query->where('account_id', $account->id),
                    ])
                    ->orderBy(
                        FestivalRequirementDefinition::query()
                            ->select('sort_order')
                            ->whereColumn('festival_requirement_definitions.id', 'festival_entry_requirements.festival_requirement_definition_id'),
                    )
                    ->orderBy('festival_entry_requirements.id'),
            ])
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function hasConfiguredFields(Account $account, FestivalEdition $edition): bool
    {
        return FestivalRequirementDefinition::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->where('show_in_media_report', true)
            ->where('input_type', FestivalRequirementInputType::File->value)
            ->exists();
    }
}
