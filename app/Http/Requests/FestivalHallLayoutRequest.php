<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalSeatMapPng;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalHallLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');
        abort_unless($account instanceof Account && $edition instanceof FestivalEdition && $edition->account_id === $account->id, 404);

        return (bool) $this->user()?->can('manageFestivalFinance', $account);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'publish' => ['required', 'boolean'],
            'floors' => ['present', 'array', 'list', 'max:12'],
            'floors.*' => ['required', 'array:id,label,width,height,stage,background,sections,blocks'],
            'floors.*.id' => ['required', 'string', 'max:48', 'alpha_dash:ascii', 'distinct'],
            'floors.*.label' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'floors.*.width' => ['required', 'integer', 'min:100', 'max:2000'],
            'floors.*.height' => ['required', 'integer', 'min:100', 'max:2000'],
            'floors.*.stage' => ['nullable', 'string', 'max:80'],
            'floors.*.background' => ['nullable', 'string', 'max:350000'],
            'floors.*.sections' => ['sometimes', 'array', 'list', 'max:80'],
            'floors.*.sections.*' => ['required', 'string', 'max:80'],
            'floors.*.blocks' => ['sometimes', 'array', 'list', 'max:80'],
            'floors.*.blocks.*' => ['required', 'array:id,kind,label,x,y,width,height'],
            'floors.*.blocks.*.id' => ['required', 'string', 'alpha_dash:ascii', 'max:48'],
            'floors.*.blocks.*.kind' => ['required', Rule::in(['stage', 'bar', 'area'])],
            'floors.*.blocks.*.label' => ['required', 'string', 'max:80'],
            'floors.*.blocks.*.x' => ['required', 'integer', 'between:0,10000'],
            'floors.*.blocks.*.y' => ['required', 'integer', 'between:0,10000'],
            'floors.*.blocks.*.width' => ['required', 'integer', 'between:100,10000'],
            'floors.*.blocks.*.height' => ['required', 'integer', 'between:100,10000'],
            'seats' => ['present', 'array', 'list', 'max:'.FestivalSeatMapPng::MAX_SEATS],
            'seats.*' => ['required', 'array:id,floor_key,section,group_kind,group_label,seat_number,x,y,festival_admission_type_id,accessibility,sightline,is_blocked'],
            'seats.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'seats.*.floor_key' => ['required', 'string', 'max:48'],
            'seats.*.section' => ['required', 'string', 'max:80'],
            'seats.*.group_kind' => ['required', Rule::in(['row', 'table'])],
            'seats.*.group_label' => ['required', 'string', 'max:40'],
            'seats.*.seat_number' => ['required', 'string', 'max:24'],
            'seats.*.x' => ['required', 'integer', 'between:0,10000'],
            'seats.*.y' => ['required', 'integer', 'between:0,10000'],
            'seats.*.festival_admission_type_id' => ['nullable', 'integer', 'min:1'],
            'seats.*.accessibility' => ['nullable', 'string', 'max:255'],
            'seats.*.sightline' => ['nullable', 'string', 'max:255'],
            'seats.*.is_blocked' => ['required', 'boolean'],
        ];
    }
}
