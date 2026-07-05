<?php

namespace App\Console\Commands\Concerns;

trait OutputsPeopleCounterDebug
{
    /**
     * @return callable(string, array<string, mixed>): void|null
     */
    protected function peopleCounterDebugCallback(): ?callable
    {
        if (! $this->option('debug')) {
            return null;
        }

        return fn (string $event, array $context = []): null => $this->writePeopleCounterDebug($event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function writePeopleCounterDebug(string $event, array $context = []): null
    {
        $payload = json_encode(
            $this->sanitizePeopleCounterDebugContext($context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->line('[people-counter] '.$event.($payload && $payload !== '[]' ? ' '.$payload : ''));

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizePeopleCounterDebugContext(array $context): array
    {
        return collect($context)
            ->mapWithKeys(fn (mixed $value, string|int $key): array => [
                $key => $this->sanitizePeopleCounterDebugValue((string) $key, $value),
            ])
            ->all();
    }

    private function sanitizePeopleCounterDebugValue(string $key, mixed $value): mixed
    {
        $normalizedKey = strtolower($key);

        if (str_contains($normalizedKey, 'url') || str_contains($normalizedKey, 'source') || str_contains($normalizedKey, 'stream')) {
            return filled($value) ? '[redacted]' : $value;
        }

        if (is_array($value)) {
            return $this->sanitizePeopleCounterDebugContext($value);
        }

        if (is_string($value)) {
            return preg_replace('#(?:rtsp|rtsps|https?)://\S+#i', '[url-redacted]', $value) ?? $value;
        }

        return $value;
    }
}
