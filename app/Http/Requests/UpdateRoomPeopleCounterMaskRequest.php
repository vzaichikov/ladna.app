<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRoomPeopleCounterMaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account && ($this->user()?->can('update', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'people_counter_mask_polygons' => ['nullable', 'json'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('people_counter_mask_polygons')) {
                    return;
                }

                foreach ($this->polygons() as $polygon) {
                    $points = $polygon['points'];

                    if (count($points) < 3 || count($points) > 50) {
                        $validator->errors()->add('people_counter_mask_polygons', __('app.people_counter_mask_polygon_invalid'));

                        return;
                    }

                    foreach ($points as $point) {
                        if ((float) $point['x'] < 0 || (float) $point['x'] > 1 || (float) $point['y'] < 0 || (float) $point['y'] > 1) {
                            $validator->errors()->add('people_counter_mask_polygons', __('app.people_counter_mask_polygon_invalid'));

                            return;
                        }
                    }
                }
            },
        ];
    }

    /**
     * @return array<int, array{points: array<int, array{x: float, y: float}>}>
     */
    public function polygons(): array
    {
        $value = (string) $this->input('people_counter_mask_polygons', '[]');
        $decoded = json_decode($value !== '' ? $value : '[]', true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->take(12)
            ->map(function (mixed $polygon): ?array {
                if (! is_array($polygon)) {
                    return null;
                }

                $points = $polygon['points'] ?? $polygon;

                if (! is_array($points)) {
                    return null;
                }

                $normalizedPoints = collect($points)
                    ->filter(fn (mixed $point): bool => is_array($point) && is_numeric($point['x'] ?? null) && is_numeric($point['y'] ?? null))
                    ->map(fn (array $point): array => [
                        'x' => round((float) $point['x'], 6),
                        'y' => round((float) $point['y'], 6),
                    ])
                    ->values()
                    ->all();

                return ['points' => $normalizedPoints];
            })
            ->filter()
            ->values()
            ->all();
    }
}
