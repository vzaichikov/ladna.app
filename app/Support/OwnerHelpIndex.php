<?php

namespace App\Support;

use Illuminate\Support\Str;

class OwnerHelpIndex
{
    private const int MaxFragmentsPerPage = 2;

    private const int MaxFragmentCharacters = 800;

    private const int MaxStepsPerFragment = 6;

    private const int MinResultScore = 20;

    private const array StopWords = [
        'a', 'about', 'an', 'and', 'are', 'for', 'how', 'i', 'in', 'is', 'me', 'of', 'on', 'or', 'the', 'to', 'what', 'where', 'with',
        'а', 'або', 'в', 'где', 'де', 'до', 'и', 'как', 'к', 'ли', 'мене', 'мени', 'мені', 'мне', 'на', 'про', 'розкажи', 'скажіть', 'что', 'чи', 'що', 'як', 'якщо',
        'будь', 'добре', 'можна', 'підкажи', 'потрібно', 'треба',
    ];

    private const array SynonymGroups = [
        ['клієнт', 'клієнта', 'клієнтку', 'клієнти', 'клиент', 'клиента', 'клиенты', 'людина', 'людину', 'дівчина', 'дівчата', 'дівчат', 'дівчатам', 'человек', 'человека', 'client', 'clients', 'customer', 'customers'],
        ['запис', 'записати', 'записувати', 'бронювання', 'бронь', 'бронювати', 'броньование', 'бронювання', 'book', 'booking', 'bookings'],
        ['заняття', 'занятие', 'занятия', 'урок', 'уроки', 'class', 'classes'],
        ['абонемент', 'абонемента', 'абонементи', 'абонементы', 'пропуск', 'пропуска', 'пропуски', 'pass', 'passes'],
        ['оплата', 'оплати', 'оплату', 'оплатить', 'payment', 'payments'],
        ['тренер', 'тренера', 'тренери', 'тренеры', 'trainer', 'trainers'],
        ['розклад', 'расписание', 'schedule'],
        ['скасувати', 'скасування', 'відновити', 'отменить', 'отмена', 'cancel', 'cancellation', 'restore'],
        ['додати', 'добавить', 'добавити', 'створити', 'создать', 'create', 'add'],
        ['видати', 'выдать', 'issue'],
        ['імпорт', 'імпортувати', 'импорт', 'import'],
        ['прайс', 'ціни', 'цена', 'цены', 'price', 'prices'],
        ['публічний', 'публичный', 'public'],
        ['асистент', 'ассистент', 'assistant', 'бот', 'bot', 'ai', 'штучний'],
        ['звіт', 'звіти', 'отчет', 'отчеты', 'report', 'reports', 'аналітика', 'аналитика', 'analytics'],
        ['довідка', 'допомога', 'помощь', 'help', 'інструкція', 'инструкция'],
    ];

    /**
     * @return array{query: string, updated_at: mixed, results: array<int, array<string, mixed>>}
     */
    public function context(string $query, int $limit = 3): array
    {
        return [
            'query' => $query,
            'updated_at' => config('help.updated_at'),
            'results' => $this->search($query, $limit),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 5): array
    {
        $normalizedQuery = $this->normalize($query);
        $tokens = $this->expandedTokens($this->tokens($normalizedQuery));

        if ($normalizedQuery === '' || $tokens === []) {
            return [];
        }

        return collect(config('help.pages', []))
            ->map(fn (array $page, string $slug): array => $this->scorePage($slug, $page, $normalizedQuery, $tokens))
            ->filter(fn (array $page): bool => $page['score'] >= self::MinResultScore)
            ->sortByDesc('score')
            ->take(max(1, $limit))
            ->values()
            ->map(fn (array $page): array => [
                'slug' => $page['slug'],
                'title' => $page['title'],
                'summary' => $page['summary'],
                'score' => $page['score'],
                'matched_sections' => collect($page['fragments'])->pluck('section_title')->filter()->unique()->values()->all(),
                'fragments' => $page['fragments'],
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array{slug: string, title: string, sections: array<int, string>}>
     */
    public function sources(array $results): array
    {
        return collect($results)
            ->map(fn (array $result): array => [
                'slug' => (string) ($result['slug'] ?? ''),
                'title' => (string) ($result['title'] ?? ''),
                'sections' => collect($result['matched_sections'] ?? [])
                    ->filter(fn (mixed $section): bool => is_string($section) && $section !== '')
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $source): bool => $source['slug'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, string>  $tokens
     * @return array<string, mixed>
     */
    private function scorePage(string $slug, array $page, string $normalizedQuery, array $tokens): array
    {
        $pageTitle = (string) ($page['title'] ?? $slug);
        $summary = (string) ($page['summary'] ?? '');
        $pageText = $this->text([
            $slug,
            $pageTitle,
            $summary,
            $page['keywords'] ?? [],
            $page['questions'] ?? [],
            $page['related'] ?? [],
        ]);

        $pageBaseScore = $this->scoreText($pageText, $normalizedQuery, $tokens, 5);
        $fragments = collect($page['sections'] ?? [])
            ->map(fn (array $section): array => $this->scoreSection($section, $normalizedQuery, $tokens))
            ->filter(fn (array $fragment): bool => $fragment['score'] > 0)
            ->sortByDesc('score')
            ->take(self::MaxFragmentsPerPage)
            ->values()
            ->map(fn (array $fragment): array => [
                'section_title' => $fragment['section_title'],
                'excerpt' => $fragment['excerpt'],
                'steps' => $fragment['steps'],
                'score' => $fragment['score'],
            ])
            ->all();

        return [
            'slug' => $slug,
            'title' => $pageTitle,
            'summary' => $summary,
            'score' => $pageBaseScore + collect($fragments)->sum('score'),
            'fragments' => $fragments,
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<int, string>  $tokens
     * @return array{section_title: string, excerpt: string, steps: array<int, string>, score: int}
     */
    private function scoreSection(array $section, string $normalizedQuery, array $tokens): array
    {
        $title = (string) ($section['title'] ?? '');
        $body = $this->stringList($section['body'] ?? []);
        $steps = $this->stringList($section['steps'] ?? []);
        $questions = $this->stringList($section['questions'] ?? []);
        $keywords = $this->stringList($section['keywords'] ?? []);

        $score = $this->scoreText($title, $normalizedQuery, $tokens, 14)
            + $this->scoreText($this->text($questions), $normalizedQuery, $tokens, 18)
            + $this->scoreText($this->text($keywords), $normalizedQuery, $tokens, 12)
            + $this->scoreText($this->text($body), $normalizedQuery, $tokens, 4)
            + $this->scoreText($this->text($steps), $normalizedQuery, $tokens, 5);

        foreach ($questions as $question) {
            $normalizedQuestion = $this->normalize($question);

            if ($normalizedQuestion !== '' && ($normalizedQuestion === $normalizedQuery || str_contains($normalizedQuestion, $normalizedQuery))) {
                $score += 160;
            }
        }

        return [
            'section_title' => $title,
            'excerpt' => $this->excerpt($body, $steps, $normalizedQuery, $tokens),
            'steps' => array_slice($steps, 0, self::MaxStepsPerFragment),
            'score' => $score,
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function scoreText(string $text, string $normalizedQuery, array $tokens, int $weight): int
    {
        $normalizedText = $this->normalize($text);

        if ($normalizedText === '') {
            return 0;
        }

        $score = 0;

        if ($normalizedQuery !== '' && str_contains($normalizedText, $normalizedQuery)) {
            $score += 40 * $weight;
        }

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 2 && str_contains($normalizedText, $token)) {
                $score += $weight;
            }
        }

        return $score;
    }

    /**
     * @param  array<int, string>  $body
     * @param  array<int, string>  $steps
     * @param  array<int, string>  $tokens
     */
    private function excerpt(array $body, array $steps, string $normalizedQuery, array $tokens): string
    {
        $candidates = collect([...$body, ...$steps])
            ->filter(fn (string $text): bool => $this->scoreText($text, $normalizedQuery, $tokens, 1) > 0)
            ->take(3)
            ->values()
            ->all();

        if ($candidates === []) {
            $candidates = array_slice($body, 0, 2);
        }

        return Str::limit($this->text($candidates), self::MaxFragmentCharacters, '');
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['ё', '’', "'"], ['е', ' ', ' '], $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?: '';

        return Str::of($value)->squish()->toString();
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $normalizedText): array
    {
        return collect(explode(' ', $normalizedText))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => $token !== '' && mb_strlen($token) >= 2 && ! in_array($token, self::StopWords, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    private function expandedTokens(array $tokens): array
    {
        return collect($tokens)
            ->flatMap(function (string $token): array {
                foreach (self::SynonymGroups as $group) {
                    if (in_array($token, $group, true)) {
                        return $group;
                    }
                }

                return [$token];
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();
    }

    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        return collect($value)
            ->flatMap(fn (mixed $item): array => is_array($item) ? [$this->text($item)] : [(string) $item])
            ->filter(fn (string $item): bool => $item !== '')
            ->implode(' ');
    }
}
