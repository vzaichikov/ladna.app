<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        $festivalEdition = $this->route('festivalEdition');
        $festivalWorkflow = $this->route('festivalWorkflow');
        $nameRule = Rule::unique('festival_workflows', 'name');

        if ($festivalEdition instanceof FestivalEdition) {
            $nameRule->where('festival_edition_id', $festivalEdition->id);
        }

        if ($festivalWorkflow instanceof FestivalWorkflow) {
            $nameRule->ignore($festivalWorkflow);
        }

        return [
            'name' => ['required', 'string', 'max:255', $nameRule],
            'application_review_mode' => [Rule::requiredIf($this->routeIs('dashboard.accounts.festivals.workflows.store')), Rule::in(['automatic', 'organizer'])],
            'technical_review_mode' => [Rule::requiredIf($this->routeIs('dashboard.accounts.festivals.workflows.store')), Rule::in(['automatic', 'organizer'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
