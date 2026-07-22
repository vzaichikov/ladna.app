<?php

namespace App\Models;

use Database\Factories\AccountOnboardingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

#[Fillable(['account_id', 'current_step', 'answers', 'completed_at'])]
class AccountOnboarding extends Model
{
    /** @use HasFactory<AccountOnboardingFactory> */
    use HasFactory;

    public const FirstStep = 1;

    public const LastStep = 6;

    protected $attributes = [
        'current_step' => 2,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'answers' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function stepAnswers(int $step): array
    {
        $answers = Arr::get($this->answers ?? [], 'steps.'.$step, []);

        return is_array($answers) ? $answers : [];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function saveStep(int $step, array $answers): void
    {
        $allAnswers = $this->answers ?? [];
        Arr::set($allAnswers, 'steps.'.$step, $answers);
        Arr::set($allAnswers, 'metrics.step_'.$step.'_completed_at', now()->toIso8601String());

        $this->forceFill([
            'answers' => $allAnswers,
            'current_step' => max($this->current_step, min(self::LastStep, $step + 1)),
        ])->save();
    }

    public function recordMetric(string $name): void
    {
        $answers = $this->answers ?? [];
        Arr::set($answers, 'metrics.'.$name, now()->toIso8601String());
        $this->forceFill(['answers' => $answers])->save();
    }

    public function recordMetricOnce(string $name): void
    {
        DB::transaction(function () use ($name): void {
            $onboarding = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $answers = $onboarding->answers ?? [];

            if (Arr::has($answers, 'metrics.'.$name)) {
                return;
            }

            Arr::set($answers, 'metrics.'.$name, now()->toIso8601String());
            $onboarding->forceFill(['answers' => $answers])->save();
            $this->setRawAttributes($onboarding->getAttributes(), true);
        });
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
